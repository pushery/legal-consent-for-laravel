<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalDocument;
use stdClass;

/**
 * Decides which mandatory documents a subject still owes acceptance for.
 *
 * The comparison is over `major_version` ONLY, never the content hash: an editorial
 * fix (same major) never forces a re-consent, while a material change (a new major,
 * published with requires_reconsent) does — but only once its enforcement window has
 * opened (EDPB 05/2020 Rz. 110; the grace period comes from enforce_from).
 *
 * Only an ACTIVE re-consent (NoticeMode::ActiveReconsent) hard-blocks. An info-only change
 * takes effect regardless and a deemed-consent change binds by silence (via the objection
 * window, not an access block) — neither gates. This is what keeps a privacy notice, which
 * is info-only, from ever blocking access (WP260 rev.01 Rz. 30-31): forcing acknowledgment
 * to regain access would be unlawful pressure.
 */
final class ConsentGate
{
    /**
     * The active, enforceable, mandatory documents whose current major version the
     * subject has not yet accepted.
     *
     * @return Collection<int, LegalDocument>
     */
    public function outstandingFor(Model $subject, string $locale, ?CarbonImmutable $now = null): Collection
    {
        $enforceable = $this->enforceable($locale, $now ?? CarbonImmutable::now());

        if ($enforceable->isEmpty()) {
            return $enforceable;
        }

        // Ask the ledger only about the keys this answer can possibly turn on. The ledger is
        // append-only and grows for the life of the account, while the enforceable set is a
        // handful of documents — without the filter the per-request cost rises with how long the
        // subject has been a customer, for rows the fold then discards.
        $held = $this->currentHoldings($subject, $this->keysOf($enforceable));

        return $enforceable
            ->filter(fn (LegalDocument $document): bool => ! self::holds($held, $document->key, $document->major_version))
            ->values();
    }

    /**
     * What {@see outstandingFor()} returns and, with `$firstAcceptance`, what
     * {@see firstAcceptanceFor()} returns, from one read of the ledger.
     *
     * The question `EnsureLegalConsent` asks on every request it guards. Asked one at a time, the
     * two read the subject's ledger twice. A document owed under both, a gating major the subject
     * has never accepted, is in the result once.
     *
     * @return Collection<int, LegalDocument>
     */
    public function owedFor(Model $subject, string $locale, bool $firstAcceptance = false, ?CarbonImmutable $now = null): Collection
    {
        $enforceable = $this->enforceable($locale, $now ?? CarbonImmutable::now());
        $mandatory = $this->mandatory($locale);
        $asked = $firstAcceptance ? $enforceable->concat($mandatory) : $enforceable;

        // The short-circuit of the two questions it joins: nothing to ask about, no ledger read.
        if ($asked->isEmpty()) {
            return $asked;
        }

        $held = $this->currentHoldings($subject, $this->keysOf($asked));

        $owed = $enforceable->filter(fn (LegalDocument $document): bool => ! self::holds($held, $document->key, $document->major_version));

        if ($firstAcceptance) {
            $owed = $owed->concat($mandatory->filter(fn (LegalDocument $document): bool => ! isset($held[$document->key])));
        }

        return $owed->unique(fn (LegalDocument $document): string => $document->key)->values();
    }

    /**
     * The active documents an active re-consent gates at `$now`: the set {@see outstandingFor()}
     * asks the ledger about.
     *
     * @return Collection<int, LegalDocument>
     */
    private function enforceable(string $locale, CarbonImmutable $now): Collection
    {
        // The active set is cached (a global, publish-driven fact); the time/mode filter runs here
        // because those windows move on a clock. So an install with nothing published does not
        // query `legal_documents` on every authenticated request just to learn that. The cache read
        // remains: on a warm store that is not the database it costs no database query, and on the
        // framework-default `database` store it is one cache-table SELECT for each locale read,
        // once per request.
        $cache = app(EnforceableDocumentCache::class);

        return $cache
            ->resolvedFor($locale)
            ->filter(fn (LegalDocument $document): bool => $document->type->isConsentBearing()
                // Informational is checked FIRST and separately, because the opt-in flag cannot
                // express it: an informational page has `requires_explicit_optin = false`, which
                // is the same value a contract carries — so the next condition alone would let an
                // Impressum block every authenticated request the moment it were published as an
                // active re-consent. Nothing a subject never accepts may gate access.
                && ! $document->requires_explicit_optin
                // Only an active re-consent gates, and it gates its whole major: an editorial fix or
                // an info-only change published inside the major keeps the gate of the version that
                // opened it, from that version's effective date.
                && $this->gateIsOpen($cache->gatingVersionOf($document), $now))
            ->values();
    }

    /**
     * The distinct keys of a set of documents, which is all the ledger read is filtered on.
     *
     * @param  Collection<int, LegalDocument>  $documents
     * @return list<string>
     */
    private function keysOf(Collection $documents): array
    {
        return array_values(array_unique($documents->map(fn (LegalDocument $document): string => $document->key)->all()));
    }

    /** Whether a major's active re-consent has reached its effective date. */
    private function gateIsOpen(?LegalDocument $gating, CarbonImmutable $now): bool
    {
        return $gating instanceof LegalDocument
            && $gating->enforce_from instanceof CarbonImmutable
            && $gating->enforce_from->lessThanOrEqualTo($now);
    }

    /**
     * The mandatory documents the subject does not hold — the first-acceptance question.
     *
     * The predicate is `held === 0`, which is wider than "never accepted": an ENDING action drops
     * the holding to zero (see {@see standingFor()}), so a withdrawn, declined or terminated
     * document lands here as well. Deliberate — that subject holds no agreement, and excluding
     * them would reinstate exactly the divergence this method exists to close.
     *
     * {@see outstandingFor()} cannot answer it, and that is not a defect in it: it filters on
     * `notice_mode`, which states how a version CHANGE is communicated. A first acceptance is not
     * a change — nothing was announced because nothing moved — so the field has no opinion to
     * give, and a document first published as a silent editorial version stayed invisible to the
     * gate for good. Measured on 0.19.0: a subject who accepted nothing got `outstanding = true`
     * from `statusFor()` and an EMPTY set from `outstanding()`, for the same documents, at the
     * same moment. The application then treated them as having accepted a text they were never
     * shown, in a ledger that is append-only.
     *
     * THE TWO QUESTIONS STAY SEPARATE, and the split is `held === 0` rather than
     * `held < major_version`. Once a subject holds ANYTHING for a key, the next version is a
     * change, and an operator who published that change as silent has said in the published row
     * that it needs no re-consent. Folding the two sets would overrule that decision from here.
     *
     * A voluntary consent is never owed (Art. 7(4): a consent that can be required is not freely
     * given), and an informational page is never accepted at all — so both are out, by the type's
     * own predicate and by the per-document override, in case an operator has made a mandatory
     * document voluntary.
     *
     * @return Collection<int, LegalDocument>
     */
    public function firstAcceptanceFor(Model $subject, string $locale): Collection
    {
        // Same cache and same short-circuit as outstandingFor(): an install that has published
        // nothing must not pay a ledger read on a path a consumer may put on every request.
        $mandatory = $this->mandatory($locale);

        if ($mandatory->isEmpty()) {
            return $mandatory;
        }

        $held = $this->currentHoldings($subject, $this->keysOf($mandatory));

        // `! isset` rather than `=== 0`: at major 0 the old spelling called an ACCEPTED document
        // unaccepted, because the fold stores that holding as the same 0 it uses for "none".
        return $mandatory
            ->filter(fn (LegalDocument $document): bool => ! isset($held[$document->key]))
            ->values();
    }

    /**
     * The active mandatory documents nobody may decline: the set {@see firstAcceptanceFor()} asks
     * the ledger about.
     *
     * @return Collection<int, LegalDocument>
     */
    private function mandatory(string $locale): Collection
    {
        return app(EnforceableDocumentCache::class)
            ->resolvedFor($locale)
            ->filter(fn (LegalDocument $document): bool => $document->type->isMandatory()
                && ! $document->requires_explicit_optin)
            ->values();
    }

    /**
     * The major version the subject CURRENTLY holds per document key, ACROSS ALL LOCALES.
     *
     * Consent attaches to a document's identity, not the language it was read in: accepting the
     * terms in `en` satisfies the `de` gate for the same document (a locale switch is a display
     * preference, not a fresh contractual encounter), and the recorded locale is provenance in
     * the ledger. See {@see standingFor()} for how the fold works.
     *
     * @param  list<string>|null  $keys  restrict the read to these document keys; null reads the
     *                                   whole ledger, which is what a full status screen needs
     * @return array<string, int>
     */
    public function heldMajorByKey(Model $subject, ?array $keys = null): array
    {
        return $this->standingFor($subject, $keys)['held'];
    }

    /**
     * The majors the subject CURRENTLY holds, keyed by document — a PRESENCE, not a number.
     *
     * THE DIFFERENCE TO {@see heldMajorByKey()} ONLY BECOMES VISIBLE AT MAJOR 0, AND THERE IT IS
     * TOTAL. That map uses 0 as its sentinel for "holds nothing", so a document published as
     * `0.9.0` folds to the same 0 as a withdrawal and as a key nobody ever touched. Every
     * comparison of the form `($held[$key] ?? 0) >= $document->major_version` is then true for
     * EVERYONE — the gate for that document is off, the settings screen tells a person they hold a
     * contract they never saw, and `outstanding` is false so it is never offered to them either.
     * Measured before this existed: a subject with an empty ledger read as `held`.
     *
     * A holding is a presence. This map carries a key only while an ACCEPTING row is the subject's
     * latest state for it. The discriminator is `standingFor()['version']`, which the fold clears
     * to null on an ending action and which is NOT NULL in the schema — so null there means "no
     * live accepting row" and can mean nothing else.
     *
     * `heldMajorByKey()` keeps its published contract (0 on ending) because consumers read it; the
     * two derive from the same single fold, so they cannot drift.
     *
     * @param  list<string>|null  $keys  restrict the read to these document keys
     * @return array<string, int>
     */
    public function currentHoldings(Model $subject, ?array $keys = null): array
    {
        return self::holdingsOf($this->standingFor($subject, $keys));
    }

    /**
     * {@see currentHoldings()} over a standing already read, for a caller that needs the other
     * projections of the same read as well.
     *
     * @param  array{held: array<string, int>, version: array<string, string|null>}  $standing  from {@see standingFor()}
     * @return array<string, int>
     */
    public static function holdingsOf(array $standing): array
    {
        return array_intersect_key(
            $standing['held'],
            array_filter($standing['version'], static fn (?string $version): bool => $version !== null),
        );
    }

    /**
     * Whether the subject currently holds $key at or above $major.
     *
     * The one place the comparison lives. Nine call sites used to spell it out with `?? 0`, and
     * every one of them was wrong for a `0.x` document in the same way.
     *
     * @param  array<string, int>  $holdings  from {@see currentHoldings()}
     */
    public static function holds(array $holdings, string $key, int $major): bool
    {
        return isset($holdings[$key]) && $holdings[$key] >= $major;
    }

    /**
     * Both projections of the subject's ledger, from ONE read of it.
     *
     * They are one method because they are one query. The settings screen wants both, and asking
     * twice would read the same rows twice for a page whose whole query budget is guarded — the
     * fold above exists precisely because this screen used to cost 1 + 2N queries. There is
     * deliberately no `pendingConfirmationKeys()` sibling: one existed for an afternoon, nothing
     * ever called it, and a public method no caller reaches is surface without a contract.
     *
     * `pending` is the middle state of a double opt-in, which the held-major fold cannot express:
     * entered but not yet confirmed folds to exactly the same zero as never entered. It is the
     * LATEST row that decides, not "has one anywhere" — a request since confirmed, withdrawn or
     * superseded is not pending, and a subject who entered themselves twice has one, not two.
     *
     * The held-major fold is withdrawal- and objection-aware rather than a monotonic MAX:
     *
     *  - an ACCEPTING action (granted / acknowledged / re-accepted / parental / deemed-accepted /
     *    confirmed) sets the held major to that row's major;
     *  - an ENDING action (withdrawn / declined / terminated) drops the holding to 0 — a monotonic
     *    max cannot see this, which is why a withdrawn opt-in wrongly reported as still held
     *    (Art. 7(3));
     *  - a NEUTRAL action keeps whatever state existed immediately before it — never a global max,
     *    which would resurrect an earlier withdrawal. Two actions are neutral, for the same reason
     *    rather than out of resemblance: an OBJECTION rebuts a *deemed change* (§ 308 Nr. 5 lit. a
     *    BGB) and not the agreement, and an OPT-IN REQUEST is the unconfirmed first half of a
     *    double opt-in. Neither grants anything and neither takes anything away.
     *
     * @param  list<string>|null  $keys  restrict the read to these document keys; null reads the
     *                                   subject's whole ledger, which is what a status screen needs
     *                                   `version` and `at` describe the SAME row the holding came from — the newest accepting one —
     *                                   and they follow it exactly: an ending action clears them to null alongside dropping the
     *                                   major to 0, a neutral action leaves them where they were. That parity is the point. A
     *                                   screen that showed a version next to a withdrawn holding would report a text as accepted
     *                                   that Art. 7(3) says is no longer held, which is the defect the withdrawal-aware fold was
     *                                   built to end rather than one to reintroduce a column later.
     *                                   `latest` and `ended` say what the four keys above cannot: which action stands newest
     *                                   for a key and when, and which action ended the holding and when. See
     *                                   {@see foldStanding()} for the rules, including why an objection after a withdrawal
     *                                   leaves `ended` alone.
     * @return array{held: array<string, int>, version: array<string, string|null>, at: array<string, string|null>, pending: list<string>, latest: array<string, array{action: string, at: string|null}>, ended: array<string, array{action: string, at: string|null}|null>}
     */
    public function standingFor(Model $subject, ?array $keys = null): array
    {
        return $this->foldStanding($this->standingRows((string) $subject->getMorphClass(), [SubjectKey::for($subject)], $keys));
    }

    /**
     * The same standing for many subjects, one query per subject type instead of one per subject.
     *
     * A page listing ten people asked {@see standingFor()} ten times, against a table that grows
     * with every re-acceptance. What made a consumer write their own batch instead of looping is
     * the fold, and that is exactly the part that must not drift: the first such copy built its
     * own list of four actions, missed two accepting ones (the deemed acceptance and the confirmed
     * double opt-in) and counted an objection as an ending. So the fold is not reimplemented here
     * — both readers call the same private one, and an arm holds their answers against each other
     * over ledgers of every shape.
     *
     * Keyed by {@see SubjectKey::pair()}, not by the subject key alone: a `User` and a `Team` can
     * both be number 1, and a map keyed by the id would hand one of them the other's standing.
     *
     * Every subject passed in gets an entry, so a caller can read the map without checking for
     * absence — one that the ledger has never seen gets the same empty standing `standingFor()`
     * returns for them. The exception is a subject whose primary key has no lossless string form,
     * which is absent for the reason {@see SubjectKey::for()} is null: it names no subject.
     *
     * @param  iterable<Model>  $subjects
     * @param  list<string>|null  $keys
     * @return array<string, array{held: array<string, int>, version: array<string, string|null>, at: array<string, string|null>, pending: list<string>, latest: array<string, array{action: string, at: string|null}>, ended: array<string, array{action: string, at: string|null}|null>}>
     */
    public function standingsFor(iterable $subjects, ?array $keys = null): array
    {
        $batches = [];

        foreach ($subjects as $subject) {
            $id = SubjectKey::for($subject);

            if ($id === null) {
                continue;
            }

            $type = (string) $subject->getMorphClass();

            // The type and the id are carried as VALUES and never read back out of an array key.
            // PHP turns a numeric-looking key into an int on the way in, and a morph ALIAS may be
            // written as a number — the same footgun {@see SubjectToken::forSubjects()} documents.
            // Grouping by the key while reading the value keeps both strings without a cast.
            //
            // De-duplicated on the way in, because the same subject twice is one subject to read
            // for, and a caller paginating a list is exactly where a duplicate turns up.
            $batches[$type]['type'] = $type;
            $batches[$type]['ids'][$id] = $id;
        }

        $standing = [];

        foreach ($batches as $batch) {
            $rowsBySubject = [];

            // Bucketing preserves the order the query imposed, so each bucket is still ordered by
            // `document_key, accepted_at, id` — which is the order the fold depends on.
            foreach ($this->standingRows($batch['type'], array_values($batch['ids']), $keys) as $row) {
                $id = is_scalar($row->subject_id) ? (string) $row->subject_id : null;

                if ($id !== null) {
                    $rowsBySubject[$id][] = $row;
                }
            }

            // Walked over the SUBJECTS rather than over the rows that came back, which is what
            // makes "every subject gets an entry" structural instead of a separate seeding pass:
            // one the ledger has never seen simply folds an empty bucket.
            foreach ($batch['ids'] as $id) {
                $standing[SubjectKey::pair($batch['type'], $id)] = $this->foldStanding($rowsBySubject[$id] ?? []);
            }
        }

        return $standing;
    }

    /**
     * The ledger rows both standing readers fold, ordered the way the fold needs them.
     *
     * `whereIn` rather than `where` for the single-subject case too, and that is a decision
     * rather than a shortcut: `subject_id` is nullable, so `where('subject_id', null)` asks
     * `is null` and would hand a subject whose key has no lossless string form every id-less row
     * of its own type as its standing. `in (null)` is never true, which is the answer the
     * many-subject reader can also give — and two readers that cannot agree are the whole reason
     * this method exists.
     *
     * @param  list<string|null>  $ids
     * @param  list<string>|null  $keys
     * @return SupportCollection<int, stdClass>
     */
    private function standingRows(string $type, array $ids, ?array $keys): SupportCollection
    {
        $tenant = app(TenantContext::class);

        return DB::table('legal_consents')
            ->select('subject_id', 'document_key', 'document_major_version', 'document_version', 'action', 'accepted_at')
            ->where('subject_type', $type)
            ->whereIn('subject_id', $ids)
            ->when($keys !== null, fn (QueryBuilder $query): QueryBuilder => $query->whereIn('document_key', $keys ?? []))
            ->when($tenant->enabled(), fn (QueryBuilder $query): QueryBuilder => $query->where('tenant_id', $tenant->current()))
            ->orderBy('document_key')
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * ONE subject's rows, folded. The only implementation of the rules the class docblock states.
     *
     * `latest` and `ended` answer the two questions a status line asks that the four older keys
     * cannot: an ended holding reads as `held = 0, version = null, at = null`, which says that
     * nothing is held and nothing at all about WHAT ended it or WHEN. "Withdrawn on 3 September"
     * needs both, and a ledger where nothing was ever held — an objection on its own, say — had
     * nothing to say beyond "not held".
     *
     * `ended` survives a later NEUTRAL row, which is the whole subtlety. An objection after a
     * withdrawal does not change what ended the consent, so `??=` keeps the earlier answer for the
     * same reason the holding keeps its prior state. An ACCEPTING row clears it to null, because
     * a holding that is live again has not been ended by anything.
     *
     * `at` is `string|null` in both new keys to match the existing `at`, whose null is the ending
     * case rather than an unreadable timestamp. One rule for the reader, not two.
     *
     * @param  iterable<stdClass>  $rows  as {@see standingRows()} returns them — `object` would be
     *                                    too wide, because a query row's columns are dynamic
     *                                    properties and only `stdClass` is allowed to have those
     * @return array{held: array<string, int>, version: array<string, string|null>, at: array<string, string|null>, pending: list<string>, latest: array<string, array{action: string, at: string|null}>, ended: array<string, array{action: string, at: string|null}|null>}
     */
    private function foldStanding(iterable $rows): array
    {
        $held = [];
        $version = [];
        $at = [];
        $latest = [];
        $ended = [];

        foreach ($rows as $row) {
            $key = $row->document_key;
            $actionValue = $row->action;

            // Both columns are declared strings and PDO hands them back as strings, so `&&` and
            // `||` would behave identically here -- no row this query can return makes the two
            // operands disagree. It stays because the fold below indexes by `$key`
            // and matches on `$actionValue`, and a narrowing at the point of use is what lets the
            // rest of this loop be read without checking the schema.
            if (is_string($key) && is_string($actionValue)) {
                $action = ConsentAction::from($actionValue);
                $major = is_numeric($row->document_major_version) ? (int) $row->document_major_version : 0;
                // As ISO 8601 with its offset, the form history() gives for the same row. The driver
                // renders the column differently by engine: PostgreSQL adds the session's offset,
                // MySQL and SQLite add none, and a value without one is read in the application's
                // timezone, as the model's own cast reads it.
                $when = is_scalar($row->accepted_at) ? CarbonImmutable::parse((string) $row->accepted_at)->toIso8601String() : null;

                $latest[$key] = ['action' => $action->value, 'at' => $when];

                if ($action->isAccepting()) {
                    $held[$key] = $major;
                    $version[$key] = is_string($row->document_version) ? $row->document_version : null;
                    $at[$key] = $when;
                    $ended[$key] = null;
                } elseif ($action->isNeutral()) {
                    $held[$key] ??= 0; // keep the prior state; only anchor the key if it is the first row
                    $version[$key] ??= null;
                    $at[$key] ??= null;
                    $ended[$key] ??= null;
                } else {
                    // Withdrawn / declined / terminated end the holding — and the version and date
                    // go with it. Leaving them behind would say "accepted 2.1.0 on the 3rd" about
                    // something the subject has since revoked.
                    $held[$key] = 0;
                    $version[$key] = null;
                    $at[$key] = null;
                    $ended[$key] = ['action' => $action->value, 'at' => $when];
                }
            }
        }

        return [
            'held' => $held,
            'version' => $version,
            'at' => $at,
            'pending' => array_keys(array_filter(
                $latest,
                static fn (array $entry): bool => $entry['action'] === ConsentAction::OptInRequested->value,
            )),
            'latest' => $latest,
            'ended' => $ended,
        ];
    }

    /**
     * The subject's most recent ledger entry for a document key — the source of truth for
     * whether they CURRENTLY hold it, since a monotonic max cannot see a later withdrawal
     * (Art. 7(3)). Cross-locale by default (identity-keyed); pass an explicit `$locale` only
     * where the answer must be per-text — the deemed-consent sweep writes one § 308 proof row
     * per locale, so it evaluates each locale's objection window on its own locale's actions.
     */
    public function latestActionFor(Model $subject, string $documentKey, ?string $locale = null): ?LegalConsent
    {
        $subjectId = SubjectKey::for($subject);

        // A subject without a key names nobody. Matched against `subject_id = null`, it would find
        // the latest row of its type that was written without a key, which is nobody's.
        if ($subjectId === null) {
            return null;
        }

        return LegalConsent::model()::query()
            ->where('subject_type', (string) $subject->getMorphClass())
            ->where('subject_id', $subjectId)
            ->where('document_key', $documentKey)
            ->when($locale !== null, fn (Builder $query): Builder => $query->where('locale', $locale))
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->first();
    }
}
