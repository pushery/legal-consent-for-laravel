<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Override;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Exceptions\LegalDocumentFrozenException;
use Pushery\LegalConsent\Exceptions\LegalDocumentInEvidenceException;
use Pushery\LegalConsent\Models\Concerns\BelongsToTenant;
use Pushery\LegalConsent\Support\ActivationLock;
use Pushery\LegalConsent\Support\EnforceableDocumentCache;
use Pushery\LegalConsent\Support\LegalDocumentPublisher;
use Pushery\LegalConsent\Support\TenantContext;

/**
 * One frozen, published version of a legal text.
 *
 * @property int $id
 * @property string $key
 * @property DocumentType $type
 * @property bool $requires_explicit_optin
 * @property string $locale
 * @property string $tenant_id
 * @property string $title
 * @property string $version
 * @property int $major_version
 * @property int $minor_version
 * @property int $patch_version
 * @property string $content_format
 * @property string $content
 * @property string $content_hash
 * @property string|null $ui_wording the acceptance sentence; NULL for an informational page, which asks the reader for nothing
 * @property string $source_driver
 * @property string|null $source_reference
 * @property bool $requires_reconsent
 * @property NoticeMode|null $notice_mode
 * @property string|null $change_class
 * @property string|null $regime
 * @property int|null $notice_period_days
 * @property bool $offers_termination
 * @property bool $keeps_unmodified_offered
 * @property string|null $change_summary SUPERSEDED by legal_change_sets / legal_change_items. Never
 *                                       written and never read: it is varchar(255) and sits outside
 *                                       MUTABLE_AFTER_PUBLISH, so it can only be set at INSERT and
 *                                       never corrected — and a change description is usually a
 *                                       structured list, not a sentence. Left in place rather than
 *                                       dropped, because removing a column from this table costs a
 *                                       proof-trigger reinstall on three engines for no gain.
 * @property bool $is_active
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $announce_from
 * @property CarbonImmutable|null $enforce_from
 * @property CarbonImmutable|null $objection_deadline
 * @property CarbonImmutable|null $objection_closed_at
 * @property CarbonImmutable|null $notified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<LegalDocument> active()
 */
final class LegalDocument extends Model
{
    use BelongsToTenant;

    /**
     * Nothing is mass-assignable. A published row is the frozen text every acceptance is proved
     * against; its only legitimate writer is {@see LegalDocumentPublisher}'s
     * curated attribute array (forceCreate). Blocking mass assignment keeps a stray
     * LegalDocument::create($input) from planting a version nobody authored — the immutability
     * trigger already refuses edits after insert, so this closes the insert side.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['content'];

    /**
     * The columns that may legitimately change after a version is published: activation
     * (`is_active`) plus the operational stamps written by the notice/objection sweeps.
     * Everything else on a published row is frozen proof.
     *
     * @var list<string>
     */
    public const array MUTABLE_AFTER_PUBLISH = ['is_active', 'updated_at', 'notified_at', 'objection_closed_at'];

    #[Override]
    protected static function booted(): void
    {
        // Keep the legacy boolean `requires_reconsent` and the first-class `notice_mode`
        // consistent on every insert, whichever the caller sets. `notice_mode` is the source of
        // truth; a caller that still sets only the boolean (pre-v0.3.0 code) gets the mapped mode,
        // and a caller that sets only the mode gets the derived boolean — so a notice-mode query
        // and a legacy `requires_reconsent` query can never disagree.
        //
        // ⚠️ This paragraph used to sit ABOVE `MUTABLE_AFTER_PUBLISH`, stacked on a second
        // docblock. PHP attaches only the LAST one to a declaration, so it was invisible to
        // reflection and to every editor — describing this hook from a place nothing connects to
        // it, while the constant it appeared to document said something else entirely.
        self::creating(function (self $document): void {
            $mode = $document->notice_mode;

            if ($mode instanceof NoticeMode) {
                $document->requires_reconsent = $mode->gates();

                return;
            }

            $mode = NoticeMode::fromLegacyReconsent((bool) $document->requires_reconsent);
            $document->notice_mode = $mode;
            $document->requires_reconsent = $mode->gates();
        });

        // A published version is frozen proof (EDPB 05/2020 Rz. 108): refuse any update that
        // touches a column outside MUTABLE_AFTER_PUBLISH, in PHP, before any SQL is issued — a
        // clean typed failure on an accidental `$doc->content = …; $doc->save()`. The database
        // trigger (migration 000011) is the defense-in-depth layer that also catches the paths
        // this hook cannot see: the two sweeps write via saveQuietly() (which bypasses events),
        // and raw DB::table()/psql updates never reach a model at all.
        self::updating(function (self $document): void {
            // array_values changes nothing observable: LegalDocumentFrozenException::for() sorts
            // the list, which re-indexes it, and only ever implodes it into its message.
            $forbidden = array_values(array_diff(array_keys($document->getDirty()), self::MUTABLE_AFTER_PUBLISH));

            if ($forbidden !== []) {
                throw LegalDocumentFrozenException::for($forbidden);
            }
        });

        // Any write to this table can change WHICH versions are enforceable, so it drops the gate's
        // cached set. Tying invalidation to the publish event alone would leave every other path
        // stale — an activate(), a seeder, a consumer inserting a row by hand — and a stale
        // enforceable set is a gate that fires late or not at all. The after-commit listener still
        // exists for the release transaction's ordering; this is the net underneath it.
        $flush = static function (self $document): void {
            $cache = app(EnforceableDocumentCache::class);

            // The row's OWN locale first, and only a delete needs it: `flushAll()` discovers
            // locales from the declared list plus the ones currently published, and a deleted row
            // is in neither by the time the listener runs. With `legal-consent.locales` undeclared
            // — the one configuration that lets a document be published in ANY language, which is
            // why this file's sibling guard exists — deleting the last document of a locale left
            // its set cached for the full TTL, so the gate kept enforcing a version that no longer
            // existed. The model still carries the attribute here; the table no longer does.
            if ($document->locale !== '') {
                $cache->flush($document->locale);
            }

            $cache->flushAll();
        };

        self::saved($flush);
        self::deleted($flush);

        // The text a subject was shown exists exactly ONCE, here, in `content`. The ledger row
        // beside it holds `content_hash` and the acceptance sentence — a fingerprint verifies a
        // text somebody produces, it cannot produce one. So deleting a version that consents point
        // at destroys the Art. 7(1) evidence for every one of them, silently: the rows survive,
        // history() still answers, and only a supervisory authority asking "what exactly did they
        // agree to?" finds that nothing can answer it any more.
        //
        // Refused, rather than warned about, because it cannot be undone and because the ledger's
        // subordinate tables (legal_change_sets / legal_change_items) have carried BEFORE DELETE
        // triggers since they existed — the load-bearing table was the unprotected one. Retirement
        // is `is_active = false`, which is what the column is for and what every retirement path in
        // the package already uses.
        self::deleting(function (self $document): void {
            $consents = $document->consentsInEvidence();

            if ($consents > 0) {
                // The three casts are for the declared string parameters and change no value: key,
                // version and locale are NOT NULL string columns, and this model casts none of them.
                throw LegalDocumentInEvidenceException::for(
                    (string) $document->key,
                    (string) $document->version,
                    (string) $document->locale,
                    $consents,
                );
            }
        });
    }

    /**
     * How many ledger rows prove themselves against THIS version.
     *
     * Both links are checked, and the second is not redundant. `document_id` is the direct one, but
     * it is nullable and migration 000008 removed its foreign key on the grounds that every ledger
     * row is self-proving through its denormalized snapshots — so a row written without it, or one
     * re-inserted by a lawful rewrite, is still an acceptance of this exact text. Counted through
     * the query builder rather than the relation, because the model's tenant scope would hide the
     * rows of every other tenant and answer zero for a version they also accepted.
     */
    private function consentsInEvidence(): int
    {
        return DB::table('legal_consents')
            ->where('document_id', $this->getKey())
            ->orWhere(fn (QueryBuilder $denormalized): QueryBuilder => $denormalized
                ->where('document_key', $this->key)
                ->where('document_version', $this->version)
                ->where('locale', $this->locale))
            ->count();
    }

    /**
     * The notice mode of this version, resolving a legacy row that predates the column
     * (null `notice_mode`) from its `requires_reconsent` boolean.
     */
    public function noticeMode(): NoticeMode
    {
        return $this->notice_mode instanceof NoticeMode
            ? $this->notice_mode
            : NoticeMode::fromLegacyReconsent((bool) $this->requires_reconsent);
    }

    /**
     * @return HasMany<LegalConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(LegalConsent::class, 'document_id');
    }

    /**
     * Only the active version of a (key, locale).
     *
     * @param  Builder<LegalDocument>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * A mandatory, active version whose enforcement window has opened.
     */
    public function isEnforceable(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->is_active
            && ! $this->requires_explicit_optin
            && $this->enforce_from instanceof CarbonImmutable
            && $this->enforce_from->lessThanOrEqualTo($now);
    }

    /**
     * Make this the single active version of its (key, locale). Portable guard for
     * "one active version" on every engine; Postgres also has a partial unique index.
     */
    public function activate(): void
    {
        // Serialize concurrent activations of the same document. PostgreSQL enforces
        // one-active-version with a partial unique index, but MySQL and SQLite have none — so two
        // concurrent publishes could each deactivate the other's predecessors and BOTH end up
        // active, failing OPEN on a production engine. A row lock is not enough: on a first publish
        // there are no rows to lock (the gap the chain race taught us), so the set is serialized by
        // NAME instead.
        //
        // Already inside a transaction? Then an orchestrating caller — the atomic multi-locale
        // releaser — owns this: it holds the very same lock across the OUTER transaction, while a
        // lock taken here would be released when this method returns, BEFORE that commit. That is
        // false comfort, not serialization, and re-taking the shared name would self-deadlock. The
        // rule is therefore explicit: whoever opens the transaction owns the lock.
        if (ActivationLock::ownedByCaller()) {
            $this->activateNow();

            return;
        }

        $this->activateSerialized();
    }

    /**
     * Take the activation lock, then activate. Public because it is the whole serialization path and
     * must be exercisable on its own: a transactional test suite can never reach it through
     * activate(), which correctly delegates whenever a transaction is already open.
     */
    public function activateSerialized(): void
    {
        // Which stores cannot serialize, what happens when they cannot, and the two timeouts all
        // live in ActivationLock — the same lock the releaser and the publisher take, resolved and
        // degraded once rather than once per caller.
        ActivationLock::serialize($this->key, 'activation', function (): void {
            $this->activateNow();
        });
    }

    /** The activation itself. Always transactional; the caller decides who holds the lock. */
    private function activateNow(): void
    {
        DB::transaction(function (): void {
            self::query()
                ->where('key', $this->key)
                ->where('locale', $this->locale)
                ->whereKeyNot($this->getKey())
                ->update(['is_active' => false]);

            $this->forceFill(['is_active' => true])->save();
        });
    }

    /**
     * The name the one-active-version set is serialized under: one lock per (tenant, KEY).
     *
     * Deliberately NOT per locale, and deliberately the same name {@see LegalDocumentReleaser} takes:
     * an atomic multi-locale release and a single `legal-consent:publish` of the same document must
     * exclude each other. Two different lock names would let them interleave — which is exactly how
     * a "serialized" activation still ends with two active rows.
     */
    public function activationLockKey(): string
    {
        return self::activationLockName($this->key);
    }

    /** The shared lock name, so every writer of a document's active version queues on one lock. */
    public static function activationLockName(string $key): string
    {
        return sprintf('legal-consent:activate:%s:%s', app(TenantContext::class)->current(), $key);
    }

    /**
     * The cache store the activation lock lives on — the package's own
     * (`legal-consent.cache.store`), not whatever happens to be the app default.
     *
     * PUBLIC AND STATIC because it has two callers, and having had two spellings is what the
     * method exists to prevent. {@see \Pushery\LegalConsent\Support\LegalDocumentReleaser} took the
     * same lock NAME through the bare `Cache` facade, which resolves the app default. Both sides
     * agreed in every test because the package store is unset there and both fell back to the same
     * default; the moment an operator sets `LEGAL_CONSENT_CACHE_STORE` — which this very docblock
     * recommends — a release and a `legal-consent:publish` of the same document held two different
     * locks and stopped excluding each other, which is precisely how a serialized activation still
     * ends with two active rows. A comparison of lock names cannot see that class of defect.
     *
     * Be honest about the limit: this guarantee is only as real as the store's lock. `array` and
     * `null` both implement LockProvider — so an interface check alone would call them protected —
     * but an array lock is process-local and a null lock always succeeds, so neither serializes
     * anything. On those the one-active-version invariant rests on PostgreSQL's partial unique index
     * alone (MySQL and SQLite have none). Use redis, memcached or database in production.
     */
    public static function activationLockStore(): Store
    {
        $name = config('legal-consent.cache.store');

        return Cache::store(is_string($name) ? $name : null)->getStore();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'requires_explicit_optin' => 'boolean',
            'major_version' => 'integer',
            'minor_version' => 'integer',
            'patch_version' => 'integer',
            'requires_reconsent' => 'boolean',
            'notice_mode' => NoticeMode::class,
            'notice_period_days' => 'integer',
            'offers_termination' => 'boolean',
            'keeps_unmodified_offered' => 'boolean',
            'is_active' => 'boolean',
            'published_at' => 'immutable_datetime',
            'announce_from' => 'immutable_datetime',
            'enforce_from' => 'immutable_datetime',
            'objection_deadline' => 'immutable_datetime',
            'objection_closed_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
