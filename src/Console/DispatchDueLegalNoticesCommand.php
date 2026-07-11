<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Pushery\LegalConsent\Contracts\LegalConsentMonitor;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Notifications\ReconsentRequired;
use Pushery\LegalConsent\Support\AffectedSubjectResolver;

/**
 * Version-level sweep: for every active material version whose announcement date has
 * passed but which has not yet been notified, notify the affected subjects and stamp a
 * `notified_at` watermark. The watermark (not a delayed job) is the reliable source of
 * truth — delayed jobs don't survive a Redis flush or deploy. Streams subjects lazily and
 * collects garbage per version (128 MB budget).
 *
 * Delivery is deliberately AT-LEAST-ONCE, not exactly-once: the watermark is stamped only
 * after a version's full subject sweep completes, so a clean re-run of a fully-processed
 * version never re-sends, but a process killed mid-sweep (deploy/OOM/timeout) leaves the
 * version un-watermarked and the next run re-notifies its subjects — including those
 * already emailed. That trade is intentional. A re-consent notice is a legal obligation
 * (§ 308 Nr. 5 lit. b BGB grace-period warning); a duplicate email is a tolerable
 * annoyance, whereas stamping the watermark up-front (exactly-once) would silently DROP the
 * notice for every not-yet-processed subject on a crash — an unacceptable compliance gap.
 * When the duplicate matters, make ReconsentRequired dedupe on its own channel.
 */
final class DispatchDueLegalNoticesCommand extends Command
{
    protected $signature = 'legal-consent:dispatch-notices';

    protected $description = 'Notify subjects who must re-consent to a now-announced material change.';

    public function handle(AffectedSubjectResolver $resolver, LegalConsentMonitor $monitor): int
    {
        DB::disableQueryLog();

        $versions = LegalDocument::query()
            ->withoutGlobalScope(TenantScope::class) // sweep every tenant's due versions
            ->where('is_active', true)
            ->where('requires_reconsent', true)
            ->where('requires_explicit_optin', false) // mandatory docs only — never nag a voluntary consent (Art. 7(4))
            ->whereNotNull('announce_from')
            ->where('announce_from', '<=', CarbonImmutable::now())
            ->whereNull('notified_at')
            ->get();

        $notified = 0;

        foreach ($versions as $version) {
            $resolver->forVersion($version)->each(function (Model $subject) use ($version, &$notified): void {
                Notification::send($subject, new ReconsentRequired($version));
                $notified++;
            });

            $version->forceFill(['notified_at' => CarbonImmutable::now()])->saveQuietly();

            gc_collect_cycles();
        }

        $monitor->heartbeat('legal-consent:dispatch-notices', $notified);

        $this->info("Dispatched {$notified} re-consent notice(s) across {$versions->count()} version(s).");

        return self::SUCCESS;
    }
}
