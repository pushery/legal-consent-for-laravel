<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Override;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Exceptions\LegalDocumentFrozenException;
use Pushery\LegalConsent\Models\Concerns\BelongsToTenant;
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
 * @property string $ui_wording
 * @property string $source_driver
 * @property string|null $source_reference
 * @property bool $requires_reconsent
 * @property NoticeMode|null $notice_mode
 * @property string|null $change_class
 * @property string|null $regime
 * @property int|null $notice_period_days
 * @property bool $offers_termination
 * @property bool $keeps_unmodified_offered
 * @property string|null $change_summary
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
     * Keep the legacy boolean `requires_reconsent` and the first-class `notice_mode`
     * consistent on every insert, whichever the caller sets. `notice_mode` is the source
     * of truth; a caller that still sets only the boolean (pre-v0.3.0 code) gets the
     * mapped mode, and a caller that sets only the mode gets the derived boolean — so a
     * notice-mode query and a legacy `requires_reconsent` query can never disagree.
     */
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
        $flush = static function (): void {
            app(EnforceableDocumentCache::class)->flushAll();
        };

        self::saved($flush);
        self::deleted($flush);
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
        if (DB::transactionLevel() > 0) {
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
        $store = $this->lockStore();

        // Two different failure shapes, and only the first is obvious:
        //  - a store with no LockProvider at all (session, storage, apc, a custom one) would make
        //    publishing FATAL, so degrade instead of throwing;
        //  - `array` and `null` DO implement LockProvider, but their locks do not serialize across
        //    processes (array is process-local; a null lock always succeeds). Those are the stores
        //    that look protected and are not, so they get the same warning.
        // Either way the activation still runs, and on PostgreSQL the partial unique index remains
        // the real guarantee. Say it rather than let the lock imply a protection it does not give.
        if (! $store instanceof LockProvider || $store instanceof ArrayStore || $store instanceof NullStore) {
            Log::warning('legal-consent: cache store cannot serialize activation, running unserialized', [
                'document_key' => $this->key,
                'store' => $store::class,
            ]);

            $this->activateNow();

            return;
        }

        $store->lock($this->activationLockKey(), 10)->block(5, function (): void {
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
     * The cache store the lock lives on — the package's own (`legal-consent.cache.store`), not
     * whatever happens to be the app default.
     *
     * Be honest about the limit: this guarantee is only as real as the store's lock. `array` and
     * `null` both implement LockProvider — so an interface check alone would call them protected —
     * but an array lock is process-local and a null lock always succeeds, so neither serializes
     * anything. On those the one-active-version invariant rests on PostgreSQL's partial unique index
     * alone (MySQL and SQLite have none). Use redis, memcached or database in production.
     */
    private function lockStore(): Store
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
