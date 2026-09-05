<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalDocument;

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
 * is info-only, from ever blocking access (WP260 rev.01 Rz. 30-31): forcing acknowledgement
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
        $now ??= CarbonImmutable::now();

        // The active set is cached (a global, publish-driven fact); the time/mode filter runs here
        // because those windows move on a clock. A dormant install now pays no query at all — it
        // used to run this on every authenticated request just to be told nothing is published.
        $enforceable = app(EnforceableDocumentCache::class)
            ->activeFor($locale)
            ->filter(fn (LegalDocument $document): bool => $document->type->isConsentBearing()
                // Informational is checked FIRST and separately, because the opt-in flag cannot
                // express it: an informational page has `requires_explicit_optin = false`, which
                // is the same value a contract carries — so the next condition alone would let an
                // Impressum block every authenticated request the moment it were published as an
                // active re-consent. Nothing a subject never accepts may gate access.
                && ! $document->requires_explicit_optin
                && $document->noticeMode() === NoticeMode::ActiveReconsent // only an active re-consent gates
                && $document->enforce_from instanceof CarbonImmutable
                && $document->enforce_from->lessThanOrEqualTo($now))
            ->values();

        if ($enforceable->isEmpty()) {
            return $enforceable;
        }

        // Ask the ledger only about the keys this answer can possibly turn on. The ledger is
        // append-only and grows for the life of the account, while the enforceable set is a
        // handful of documents — without the filter the per-request cost rises with how long the
        // subject has been a customer, for rows the fold then discards.
        $held = $this->heldMajorByKey($subject, array_values($enforceable->map(fn (LegalDocument $document): string => $document->key)->all()));

        return $enforceable
            ->filter(fn (LegalDocument $document): bool => ($held[$document->key] ?? 0) < $document->major_version)
            ->values();
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
        $mandatory = app(EnforceableDocumentCache::class)
            ->activeFor($locale)
            ->filter(fn (LegalDocument $document): bool => $document->type->isMandatory()
                && ! $document->requires_explicit_optin)
            ->values();

        if ($mandatory->isEmpty()) {
            return $mandatory;
        }

        $held = $this->heldMajorByKey($subject, array_values($mandatory->map(fn (LegalDocument $document): string => $document->key)->all()));

        return $mandatory
            ->filter(fn (LegalDocument $document): bool => ($held[$document->key] ?? 0) === 0)
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
     * @return array{held: array<string, int>, version: array<string, string|null>, at: array<string, string|null>, pending: list<string>}
     */
    public function standingFor(Model $subject, ?array $keys = null): array
    {
        $tenant = app(TenantContext::class);

        $rows = DB::table('legal_consents')
            ->select('document_key', 'document_major_version', 'document_version', 'action', 'accepted_at')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', SubjectKey::for($subject))
            ->when($keys !== null, fn (QueryBuilder $query): QueryBuilder => $query->whereIn('document_key', $keys ?? []))
            ->when($tenant->enabled(), fn (QueryBuilder $query): QueryBuilder => $query->where('tenant_id', $tenant->current()))
            ->orderBy('document_key')
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->get();

        $held = [];
        $version = [];
        $at = [];
        $latest = [];

        foreach ($rows as $row) {
            $key = $row->document_key;
            $actionValue = $row->action;

            // Both columns are declared strings and PDO hands them back as strings, so the
            // BooleanAndToBooleanOr mutant here is equivalent -- no row this query can return
            // makes the two operands disagree. It stays because the fold below indexes by `$key`
            // and matches on `$actionValue`, and a narrowing at the point of use is what lets the
            // rest of this loop be read without checking the schema.
            if (is_string($key) && is_string($actionValue)) {
                $action = ConsentAction::from($actionValue);
                $major = is_numeric($row->document_major_version) ? (int) $row->document_major_version : 0;

                $latest[$key] = $action;

                if ($action->isAccepting()) {
                    $held[$key] = $major;
                    $version[$key] = is_string($row->document_version) ? $row->document_version : null;
                    // Normalized to a string here rather than left as whatever the driver returns:
                    // SQLite hands back a string, Postgres a string, and a consumer comparing two
                    // of these should not have to know which engine produced them.
                    $at[$key] = is_scalar($row->accepted_at) ? (string) $row->accepted_at : null;
                } elseif ($action->isNeutral()) {
                    $held[$key] ??= 0; // keep the prior state; only anchor the key if it is the first row
                    $version[$key] ??= null;
                    $at[$key] ??= null;
                } else {
                    // Withdrawn / declined / terminated end the holding — and the version and date
                    // go with it. Leaving them behind would say "accepted 2.1.0 on the 3rd" about
                    // something the subject has since revoked.
                    $held[$key] = 0;
                    $version[$key] = null;
                    $at[$key] = null;
                }
            }
        }

        return [
            'held' => $held,
            'version' => $version,
            'at' => $at,
            'pending' => array_keys(array_filter(
                $latest,
                static fn (ConsentAction $action): bool => $action === ConsentAction::OptInRequested,
            )),
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
        return LegalConsent::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', SubjectKey::for($subject))
            ->where('document_key', $documentKey)
            ->when($locale !== null, fn (Builder $query): Builder => $query->where('locale', $locale))
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->first();
    }
}
