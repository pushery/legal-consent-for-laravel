<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Contracts\LegalConsentMonitor;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\LegalNotice;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Support\AffectedSubjectResolver;
use Pushery\LegalConsent\Support\ConsentContext;
use Pushery\LegalConsent\Support\ConsentGate;
use Pushery\LegalConsent\Support\DeemedAcceptanceDecision;
use Pushery\LegalConsent\Support\TenantContext;

/**
 * Closes the objection window of a deemed-consent (Zustimmungsfiktion) change: for every
 * active DeemedConsent version whose objection deadline has passed but which has not yet been
 * closed, append a system-generated `DeemedAccepted` ledger row for every affected subject
 * who neither objected nor terminated in time — so silence binds PROVABLY (§ 308 Nr. 5 BGB),
 * without ever hard-blocking access. Streams subjects lazily and collects garbage per version
 * (128 MB budget), mirroring the notice sweep.
 *
 * Idempotent twice over: the per-version `objection_closed_at` watermark stops a re-scan, and the
 * decision refuses a subject who already holds this version (a DeemedAccepted row gives them it),
 * so even a mid-run crash + re-run never double-deems a subject.
 *
 * SILENCE BINDS ONLY AGAINST A PROOF ROW. § 308 Nr. 5 lit. b BGB makes the special warning a
 * validity condition of the fiction, so this sweep deems nobody it cannot show a delivered notice
 * for — a `legal_notices` row for that subject and version whose mandatory content actually
 * rendered. Refusals are counted and reported: doing nothing quietly is the worst outcome here,
 * because it looks exactly like success.
 *
 * That makes the durable-medium proof a PREREQUISITE of deemed consent rather than an option. With
 * `durable_medium.proof` off no such row is ever written, so the sweep refuses to run at all rather
 * than deem an entire population against no evidence — and says so, instead of leaving an operator
 * to discover it from an empty ledger.
 */
final class CloseObjectionWindowsCommand extends Command
{
    /** Subjects per batch — one notice-proof lookup per chunk instead of one per subject. */
    private const int CHUNK = 500;

    protected $signature = 'legal-consent:close-objection-windows';

    protected $description = 'Deem acceptance for deemed-consent changes whose objection window has closed with no objection.';

    public function handle(AffectedSubjectResolver $resolver, ConsentGate $gate, ConsentManager $consent, LegalConsentMonitor $monitor, TenantContext $tenant, DeemedAcceptanceDecision $decision): int
    {
        DB::disableQueryLog();

        $now = CarbonImmutable::now();

        // Snapshot the ledger BEFORE writing anything: this sweep appends an accepting
        // (DeemedAccepted) row per subject, which would otherwise drop that subject out of the
        // resolver's `HAVING MAX(major) < …` set mid-stream and make the LIMIT/OFFSET paging skip
        // subjects it never returns — who would then be locked out for good by objection_closed_at.
        $latestConsentId = DB::table('legal_consents')->max('id');
        $maxConsentId = is_numeric($latestConsentId) ? (int) $latestConsentId : 0;

        $versions = LegalDocument::query()
            ->withoutGlobalScope(TenantScope::class) // close every tenant's due windows
            ->where('is_active', true)
            ->where('notice_mode', NoticeMode::DeemedConsent->value)
            ->whereNotNull('objection_deadline')
            ->where('objection_deadline', '<=', $now)
            ->whereNull('objection_closed_at')
            ->get();

        if ($versions->isNotEmpty() && ! (bool) config('legal-consent.durable_medium.proof', true)) {
            // Refuse the WHOLE run rather than close windows that can deem nobody. Closing them
            // would burn the one watermark that lets a corrected configuration try again, and it
            // would do it while reporting success.
            $this->error('Deemed consent needs the durable-medium proof: `legal-consent.durable_medium.proof` is off, so no notice proof is ever written and § 308 Nr. 5 lit. b silence cannot lawfully bind anyone. Turn it on, re-run the notice dispatch for the affected versions, then close their windows.');

            return self::FAILURE;
        }

        $deemed = 0;
        $unproved = 0;

        foreach ($versions as $version) {
            $resolver->forVersion($version, $maxConsentId)
                ->chunk(self::CHUNK)
                ->each(function (LazyCollection $chunk) use ($version, $gate, $consent, $tenant, $decision, &$deemed, &$unproved): void {
                    $subjects = $chunk->collect();
                    // One query per chunk, not one per subject: the answer to "was this subject
                    // sent a valid notice for this version" is the same table for all of them.
                    $proved = $this->provenSubjects($version, $subjects);

                    foreach ($subjects as $subject) {
                        // Pin the version's tenant around the WHOLE evaluation — the read as much as
                        // the write. A scheduled/console run has no authenticated user, so an
                        // unpinned read would query the shared '' bucket (LegalConsent carries the
                        // TenantScope) and miss the subject's own Objected row — deeming someone who
                        // objected in time to have agreed by silence. An unpinned write would
                        // likewise strand the § 308 proof outside the tenant it belongs to.
                        $tenant->forTenant($version->tenant_id, function () use ($version, $subject, $gate, $consent, $decision, $proved, &$deemed, &$unproved): void {
                            // Read LIVE (not from the snapshot): only this can see an objection or an
                            // express acceptance recorded since the sweep started.
                            $latest = $gate->latestActionFor($subject, $version->key, $version->locale);
                            $noticeProved = isset($proved[$this->subjectKey($subject)]);

                            if (! $decision->shouldDeem($latest, $version, $noticeProved)) {
                                // Separate the two refusals, and ask in this order. Someone who
                                // objected, terminated, or already holds this version is the system
                                // working — counting them as a missing notice would bury the real
                                // deficiency in a number that is never zero. Only a subject who
                                // WOULD have been bound, and cannot be for want of proof, counts.
                                if (! $noticeProved && $decision->shouldDeem($latest, $version, noticeProved: true)) {
                                    $unproved++;
                                }

                                return;
                            }

                            $consent->record(
                                $subject,
                                $version->key,
                                ConsentAction::DeemedAccepted,
                                ConsentContext::forMethod(ConsentMethod::DeemedAcceptance),
                                $version->locale,
                            );

                            $deemed++;
                        });
                    }
                });

            $version->forceFill(['objection_closed_at' => $now])->saveQuietly();

            gc_collect_cycles();
        }

        $monitor->heartbeat('legal-consent:close-objection-windows', $deemed);

        $this->info("Deemed {$deemed} acceptance(s) across {$versions->count()} closed objection window(s).");

        if ($unproved > 0) {
            // Loud, and non-zero. A silent skip here is indistinguishable from "nobody was owed
            // anything", and the difference is whether a population is bound or not.
            $this->error("{$unproved} subject(s) were NOT deemed: no delivered notice carrying the § 308 Nr. 5 lit. b warning is on record for them. Silence does not bind without it. Check `legal-consent:dispatch-notices` ran for these versions, and that legal_notices.mandatory_content_ok is true.");
            $monitor->heartbeat('legal-consent:close-objection-windows.unproved', $unproved);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The subjects of this chunk that carry a delivered, non-deficient notice for this version.
     *
     * @param  Collection<int, Model>  $subjects
     * @return array<string, true>
     */
    private function provenSubjects(LegalDocument $version, Collection $subjects): array
    {
        // No empty-collection guard: chunk() never yields an empty chunk, so this cannot be called
        // with one, and `whereIn(…, [])` is valid SQL anyway. A branch that cannot run is not
        // defense — it is a line that can never be shown to work.
        $rows = LegalNotice::query()
            ->withoutGlobalScope(TenantScope::class) // the sweep crosses tenants and runs unauthenticated
            ->where('document_id', $version->getKey())
            ->where('mandatory_content_ok', true)
            ->whereIn('subject_id', $subjects->map(fn (Model $subject): mixed => $subject->getKey())->all())
            ->whereIn('subject_type', $subjects->map(fn (Model $subject): string => $subject->getMorphClass())->unique()->values()->all())
            ->get(['subject_type', 'subject_id']);

        $proved = [];

        foreach ($rows as $row) {
            $proved[$row->subject_type.'#'.$row->subject_id] = true;
        }

        return $proved;
    }

    private function subjectKey(Model $subject): string
    {
        $key = $subject->getKey();

        // A subject with no key cannot own a proof row, so it can never be proven — the resolver
        // only ever hydrates persisted models, but stringifying `mixed` is not something to assume.
        return $subject->getMorphClass().'#'.(is_scalar($key) ? (string) $key : '');
    }
}
