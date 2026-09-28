<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Pushery\LegalConsent\Console\Concerns\SkipsWhenTablesAreMissing;
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
use Pushery\LegalConsent\Support\DeemedAcceptanceDecision;
use Pushery\LegalConsent\Support\LegalDocumentPublisher;
use Pushery\LegalConsent\Support\SubjectKey;
use Pushery\LegalConsent\Support\TenantContext;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Closes the objection window of a deemed-consent (Zustimmungsfiktion) change: for every
 * active DeemedConsent version whose objection deadline has passed but which has not yet been
 * closed, append a system-generated `DeemedAccepted` ledger row for every affected subject
 * who neither objected nor terminated in time — so silence binds PROVABLY (§ 308 Nr. 5 BGB),
 * without ever hard-blocking access. Streams subjects lazily and collects garbage per version, so
 * peak memory does not grow with the size of the audience — mirroring the notice sweep.
 *
 * Idempotent twice over: the per-version `objection_closed_at` watermark stops a re-scan, and the
 * decision refuses a subject who already holds this version (a DeemedAccepted row gives them it),
 * so even a mid-run crash + re-run never double-deems a subject.
 *
 * THE WATERMARK IS STAMPED ONLY ON A VERSION THAT WAS FULLY WORKED THROUGH. A version that could
 * not deem part of its population keeps its window open, so this task stays red until somebody
 * acts on it — the notice sweep applies the same rule to both of its hold-backs. Stamping a
 * partially handled version retires it from the selection above while that population is still
 * unbound: the first run exits non-zero, and every run after it is green over a state nothing
 * restored.
 *
 * SILENCE BINDS ONLY AGAINST A PROOF ROW. § 308 Nr. 5 lit. b BGB makes the special warning a
 * validity condition of the fiction, so this sweep deems nobody it cannot show a delivered notice
 * for — a `legal_notices` row for that subject and version whose mandatory content actually
 * rendered, and which was delivered by the objection deadline. The warning belongs at the start of
 * the period to object, so a notice delivered after the deadline leaves that period empty and binds
 * nobody. Refusals are counted and reported: doing nothing quietly is the worst outcome here,
 * because it looks exactly like success.
 *
 * That makes the durable-medium proof a PREREQUISITE of deemed consent rather than an option. With
 * `durable_medium.proof` off no such row is ever written, so the sweep refuses to run at all rather
 * than deem an entire population against no evidence — and says so, instead of leaving an operator
 * to discover it from an empty ledger.
 */
#[AsCommand(name: 'legal-consent:close-objection-windows')]
final class CloseObjectionWindowsCommand extends Command implements Isolatable
{
    use SkipsWhenTablesAreMissing;

    /** Subjects per batch — one notice-proof lookup per chunk instead of one per subject. */
    private const int CHUNK = 500;

    protected $signature = 'legal-consent:close-objection-windows';

    protected $description = 'Deem acceptance for deemed-consent changes whose objection window has closed with no objection.';

    public function handle(AffectedSubjectResolver $resolver, ConsentManager $consent, LegalConsentMonitor $monitor, TenantContext $tenant, DeemedAcceptanceDecision $decision, LegalDocumentPublisher $publisher): int
    {
        DB::disableQueryLog();

        if ($this->tablesAreMissing(['legal_documents', 'legal_consents', 'legal_notices'])) {
            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();

        // Snapshot the ledger BEFORE writing anything: this sweep appends an accepting
        // (DeemedAccepted) row per subject, which would otherwise drop that subject out of the
        // resolver's `HAVING MAX(major) < …` set mid-stream and make the LIMIT/OFFSET paging skip
        // subjects it never returns — who would then be locked out for good by objection_closed_at.
        $latestConsentId = DB::table('legal_consents')->max('id');
        // The cast makes the watermark the int forVersion() takes: through a connection that
        // stringifies fetched values, the highest id arrives as a string. The 0 is the watermark of
        // an empty ledger, which owes nobody below any number.
        $maxConsentId = is_numeric($latestConsentId) ? (int) $latestConsentId : 0;

        $versions = LegalDocument::model()::query()
            ->withoutGlobalScope(TenantScope::class) // close every tenant's due windows
            ->where('is_active', true)
            ->where('notice_mode', NoticeMode::DeemedConsent->value)
            ->whereNotNull('objection_deadline')
            ->where('objection_deadline', '<=', $now)
            ->whereNull('objection_closed_at')
            ->get();

        if ($versions->isNotEmpty() && ! config('legal-consent.durable_medium.proof', true)) {
            // Refuse the WHOLE run rather than close windows that can deem nobody. Closing them
            // would burn the one watermark that lets a corrected configuration try again, and it
            // would do it while reporting success.
            $this->error('Deemed consent needs the durable-medium proof: `legal-consent.durable_medium.proof` is off, so no notice proof is ever written and § 308 Nr. 5 lit. b silence cannot lawfully bind anyone. Turn it on, re-run the notice dispatch for the affected versions, then close their windows.');

            return self::FAILURE;
        }

        $deemed = 0;
        $unproved = 0;
        $late = 0;
        $closed = 0;

        foreach ($versions as $version) {
            $unprovedHere = 0;
            $lateHere = 0;

            $resolver->forVersion($version, $maxConsentId)
                ->chunk(self::CHUNK)
                ->each(function (LazyCollection $chunk) use ($version, $consent, $tenant, $decision, $publisher, &$deemed, &$unprovedHere, &$lateHere): void {
                    $subjects = $chunk->collect();
                    // One query per chunk, not one per subject: the answer to "was this subject
                    // sent a valid notice for this version" is the same table for all of them.
                    [$proved, $delivered] = $this->provenSubjects($version, $subjects, $publisher);

                    foreach ($subjects as $subject) {
                        // Pin the version's tenant around the WHOLE evaluation — the read as much as
                        // the write. A scheduled/console run has no authenticated user, so an
                        // unpinned read would query the shared '' bucket (LegalConsent carries the
                        // TenantScope) and miss the subject's own Objected row — deeming someone who
                        // objected in time to have agreed by silence. An unpinned write would
                        // likewise strand the § 308 proof outside the tenant it belongs to.
                        $tenant->forTenant($version->tenant_id, function () use ($version, $subject, $consent, $decision, $proved, $delivered, &$deemed, &$unprovedHere, &$lateHere): void {
                            // Read LIVE (not from the snapshot): only this can see an objection or an
                            // express acceptance recorded since the sweep started. An objection that
                            // answered no open change is passed over there, see latestThatCounts().
                            $latest = $decision->latestThatCounts($subject, $version);
                            $key = $this->subjectKey($subject);
                            $noticeProved = isset($proved[$key]);

                            if (! $decision->shouldDeem($latest, $version, $noticeProved)) {
                                // Separate the two refusals, and ask in this order. Someone who
                                // objected, terminated, or already holds this version is the system
                                // working — counting them as a missing notice would bury the real
                                // deficiency in a number that is never zero. Only a subject who
                                // WOULD have been bound, and cannot be for want of proof, counts,
                                // and a notice that did arrive, only too late, is counted apart.
                                if (! $noticeProved && $decision->shouldDeem($latest, $version, noticeProved: true)) {
                                    if (isset($delivered[$key])) {
                                        $lateHere++;
                                    } else {
                                        $unprovedHere++;
                                    }
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

            // STAMP ONLY A VERSION THAT WAS FULLY WORKED THROUGH — the rule the notice sweep
            // already applies to both of its hold-backs ("Skip WITHOUT stamping. The version stays
            // due"). Line 78 above selects on `objection_closed_at is null`, so stamping a version
            // that could not deem part of its population retires it while that population stays
            // unbound: the first run exits 1, every run after it exits 0 over a state nothing
            // restored, and § 308 Nr. 5 BGB never takes hold for those subjects.
            //
            // Leaving it open keeps the alarm STICKY, which is the point. It does not promise that
            // a late notice can still bind anyone — a § 308 Nr. 5 lit. b warning served after the
            // objection deadline cannot found a fiction, and re-announcing is a new version's job.
            // It promises that the scheduled task keeps saying so until somebody acts.
            if ($unprovedHere === 0 && $lateHere === 0) {
                $version->forceFill(['objection_closed_at' => $now])->saveQuietly();
                $closed++;
            }

            $unproved += $unprovedHere;
            $late += $lateHere;

            // A memory hint, and observable after all: with the automatic collector off, a cycle left
            // before the run is freed only by this call, and an arm holds that. It stays because this
            // sweep walks the ledger in chunks and the cycles it drops are real.
            gc_collect_cycles();
        }

        $monitor->heartbeat('legal-consent:close-objection-windows', $deemed);

        $this->info("Deemed {$deemed} acceptance(s) across {$closed} closed objection window(s).");

        if ($unproved > 0) {
            // Loud, and non-zero. A silent skip here is indistinguishable from "nobody was owed
            // anything", and the difference is whether a population is bound or not.
            $this->error("{$unproved} subject(s) were NOT deemed: no notice carrying the § 308 Nr. 5 lit. b warning is on record as delivered to them by the objection deadline. Silence does not bind without it, and a notice sent now would arrive after the deadline it names: they need a new version with a new objection deadline. Their window stays OPEN, so this run keeps failing until the version is replaced — check that `legal-consent:dispatch-notices` runs on schedule, and that legal_notices.mandatory_content_ok is true.");
            $monitor->heartbeat('legal-consent:close-objection-windows.unproved', $unproved);
        }

        if ($late > 0) {
            // Apart from the missing ones, because the repair differs: the dispatch did reach these
            // subjects, only too late, and sending again cannot change when.
            $this->error("{$late} subject(s) were NOT deemed: their notice carrying the § 308 Nr. 5 lit. b warning was delivered too late to leave them the objection period this version owes, after the objection deadline or too close to it, so silence cannot bind them to this version. They need a new version with a new objection deadline. Their window stays OPEN, so this run keeps failing until the version is replaced.");
            $monitor->heartbeat('legal-consent:close-objection-windows.late', $late);
        }

        return $unproved > 0 || $late > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The subjects of this chunk that carry a delivered, non-deficient notice for this version, and
     * of those the ones it reached by the objection deadline.
     *
     * @param  Collection<int, Model>  $subjects
     * @return array{0: array<string, true>, 1: array<string, true>} delivered by the deadline, and delivered at all
     */
    private function provenSubjects(LegalDocument $version, Collection $subjects, LegalDocumentPublisher $publisher): array
    {
        // No empty-collection guard: chunk() never yields an empty chunk, so this cannot be called
        // with one, and `whereIn(…, [])` is valid SQL anyway. A branch that cannot run is not
        // defense — it is a line that can never be shown to work.
        $rows = LegalNotice::model()::query()
            ->withoutGlobalScope(TenantScope::class) // the sweep crosses tenants and runs unauthenticated
            ->where('document_id', $version->getKey())
            ->where('mandatory_content_ok', true)
            ->whereIn('subject_id', $subjects->map(fn (Model $subject): ?string => SubjectKey::for($subject))->all())
            // Cast for the reason SubjectToken carries the same one: a morph ALIAS written as a
            // number comes back as the int PHP made of that array key, and the closure would then
            // violate its own return type — a fatal sweep, on a configuration Laravel allows.
            ->whereIn('subject_type', $subjects->map(fn (Model $subject): string => (string) $subject->getMorphClass())->unique()->values()->all())
            ->get(['subject_type', 'subject_id', 'sent_at']);

        $proved = [];
        $delivered = [];

        foreach ($rows as $row) {
            // The values are placeholders: both maps are read with `isset()`, which is true for
            // `false` just as it is for `true` — said out loud, because a value that could be
            // anything invites somebody to conclude it matters.
            $key = $row->subject_type.'#'.$row->subject_id;
            $delivered[$key] = true;

            // On time means with the objection period still ahead of the recipient, measured from the
            // day this notice was delivered, not merely before the deadline.
            if ($publisher->leavesObjectionPeriod($version, $row->sent_at)) {
                $proved[$key] = true;
            }
        }

        return [$proved, $delivered];
    }

    private function subjectKey(Model $subject): string
    {
        $key = $subject->getKey();

        // A subject with no key cannot own a proof row, so it can never be proven — the resolver
        // only ever hydrates persisted models, but stringifying `mixed` is not something to assume.
        // The '' is EQUIVALENT under mutation for the same reason: no keyless subject reaches this.
        return $subject->getMorphClass().'#'.(is_scalar($key) ? $key : '');
    }
}
