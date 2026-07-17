<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Contracts\LegalConsentMonitor;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalNotice;
use Pushery\LegalConsent\Models\Scopes\TenantScope;

/**
 * Delete consent records past the retention period (default 3 years, § 31 Abs. 2 OWiG /
 * § 195 BGB). DELETE is the one mutation the append-only ledger allows; anonymization of a
 * live record is deliberately NOT offered (it would be an UPDATE, which the ledger blocks)
 * — deleting the subject instead orphans the record while the subject_token pseudonym
 * survives. Chunked with per-chunk GC (128 MB budget).
 *
 * Retention is measured from acceptance, but a subject's CURRENT standing — the newest
 * ledger row for a (subject, document, locale) — is never pruned by age alone: deleting it
 * would destroy the very Art. 7(1) proof the still-active relationship depends on. Only
 * SUPERSEDED rows (an even newer row exists for the same triple) and ORPHANED rows (the
 * subject was deleted, so no live relationship remains) are eligible.
 *
 * The notice-delivery ledger is pruned on the SAME rule, and must be: it holds personal data
 * (the subject and the exact message they were served), so leaving it to grow forever would
 * break storage limitation (Art. 5(1)(e)) — the very principle the retention period exists
 * for. Its newest row per triple is kept for the same reason: it is what proves the change
 * behind the subject's current standing was lawfully announced, which for a deemed acceptance
 * is what makes silence binding at all (§ 308 Nr. 5 lit. b).
 */
final class PruneExpiredConsentRecordsCommand extends Command
{
    protected $signature = 'legal-consent:prune';

    protected $description = 'Delete consent and notice records older than the configured retention period.';

    public function handle(LegalConsentMonitor $monitor): int
    {
        DB::disableQueryLog();

        $retention = config('legal-consent.retention_after_end', '3 years');
        $retention = is_string($retention) && $retention !== '' ? $retention : '3 years';
        $cutoff = CarbonImmutable::now()->modify("-{$retention}");

        $consents = $this->prune(LegalConsent::query(), 'legal_consents', 'accepted_at', $cutoff);
        $notices = $this->prune(LegalNotice::query(), 'legal_notices', 'sent_at', $cutoff);

        // Report even a zero sweep: this is the one scheduled task whose SILENCE is the failure.
        // A dispatch that stops running leaves visibly missing mail; a prune that stops running
        // just keeps personal data past its retention period, with nothing failing and nobody
        // noticing (Art. 5(1)(e)). The heartbeat is what makes that detectable.
        $monitor->heartbeat('legal-consent:prune', $consents + $notices);

        $this->info("Pruned {$consents} consent record(s) and {$notices} notice record(s) older than {$retention}.");

        return self::SUCCESS;
    }

    /**
     * Eligible = past the cutoff AND (orphaned OR superseded). Orphaned = the subject was
     * deleted (no live relationship remains). Superseded = a newer row exists for the same
     * (subject, document, locale) triple, so this older one is no longer the current standing.
     * The newest row per triple has no such successor and is kept, even past the cutoff, so an
     * active subject's proof is never destroyed by age. The whole disjunction is parenthesised
     * so it binds under the cutoff, not beside it.
     *
     * @template TModel of LegalConsent|LegalNotice
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $table  the ledger's table. It is interpolated into the correlated
     *                                 subquery, so the type is the guard: only a compile-time
     *                                 constant may ever reach it, never a caller's value.
     */
    private function prune(Builder $query, string $table, string $timestamp, CarbonImmutable $cutoff): int
    {
        $deleted = 0;

        $query
            ->withoutGlobalScope(TenantScope::class) // retention runs across all tenants
            ->select(['id'])
            ->where($timestamp, '<', $cutoff)
            ->whereRaw(
                '(subject_id is null or subject_type is null or exists ('
                ."select 1 from {$table} as newer "
                ."where newer.subject_type = {$table}.subject_type "
                ."and newer.subject_id = {$table}.subject_id "
                ."and newer.document_key = {$table}.document_key "
                ."and newer.locale = {$table}.locale "
                ."and newer.id > {$table}.id))"
            )
            ->orderBy('id')
            ->chunkById(1000, function (Collection $rows) use ($table, &$deleted): void {
                $deleted += DB::table($table)->whereIn('id', $rows->pluck('id')->all())->delete();

                gc_collect_cycles();
            });

        return $deleted;
    }
}
