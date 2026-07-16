<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Contracts\LegalConsentMonitor;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalDocument;
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
 * Idempotent twice over: the per-version `objection_closed_at` watermark stops a re-scan, and
 * the affected-subject resolver excludes anyone already at the current major (a DeemedAccepted
 * row raises them), so even a mid-run crash + re-run never double-deems a subject.
 */
final class CloseObjectionWindowsCommand extends Command
{
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

        $deemed = 0;

        foreach ($versions as $version) {
            $resolver->forVersion($version, $maxConsentId)->each(function (Model $subject) use ($version, $gate, $consent, $tenant, $decision, &$deemed): void {
                // Pin the version's tenant around the WHOLE evaluation — the read as much as the
                // write. A scheduled/console run has no authenticated user, so an unpinned read
                // would query the shared '' bucket (LegalConsent carries the TenantScope) and miss
                // the subject's own Objected row — deeming someone who objected in time to have
                // agreed by silence. An unpinned write would likewise strand the § 308 proof
                // outside the tenant it belongs to.
                $tenant->forTenant($version->tenant_id, function () use ($version, $subject, $gate, $consent, $decision, &$deemed): void {
                    // Read LIVE (not from the snapshot): only this can see an objection or an
                    // express acceptance recorded since the sweep started.
                    $latest = $gate->latestActionFor($subject, $version->key, $version->locale);

                    if (! $decision->shouldDeem($latest, $version)) {
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
            });

            $version->forceFill(['objection_closed_at' => $now])->saveQuietly();

            gc_collect_cycles();
        }

        $monitor->heartbeat('legal-consent:close-objection-windows', $deemed);

        $this->info("Deemed {$deemed} acceptance(s) across {$versions->count()} closed objection window(s).");

        return self::SUCCESS;
    }
}
