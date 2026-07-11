<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Models\LegalConsent;
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
 */
final class PruneExpiredConsentRecordsCommand extends Command
{
    protected $signature = 'legal-consent:prune';

    protected $description = 'Delete consent records older than the configured retention period.';

    public function handle(): int
    {
        DB::disableQueryLog();

        $retention = config('legal-consent.retention_after_end', '3 years');
        $retention = is_string($retention) && $retention !== '' ? $retention : '3 years';
        $cutoff = CarbonImmutable::now()->modify("-{$retention}");

        $deleted = 0;

        // Eligible = past the cutoff AND (orphaned OR superseded). Orphaned = the subject was
        // deleted (no live relationship remains). Superseded = a newer ledger row exists for
        // the same (subject, document, locale) triple, so this older one is no longer the
        // current standing. The newest row per triple has no such successor and is kept, even
        // past the cutoff, so an active subject's Art. 7(1) proof is never destroyed by age.
        // The whole disjunction is parenthesised so it binds under the cutoff, not beside it.
        LegalConsent::query()
            ->withoutGlobalScope(TenantScope::class) // retention runs across all tenants
            ->select(['id'])
            ->where('accepted_at', '<', $cutoff)
            ->whereRaw(
                '(subject_id is null or subject_type is null or exists ('
                .'select 1 from legal_consents as newer '
                .'where newer.subject_type = legal_consents.subject_type '
                .'and newer.subject_id = legal_consents.subject_id '
                .'and newer.document_key = legal_consents.document_key '
                .'and newer.locale = legal_consents.locale '
                .'and newer.id > legal_consents.id))'
            )
            ->orderBy('id')
            ->chunkById(1000, function (Collection $rows) use (&$deleted): void {
                $deleted += DB::table('legal_consents')->whereIn('id', $rows->pluck('id')->all())->delete();

                gc_collect_cycles();
            });

        $this->info("Pruned {$deleted} consent record(s) older than {$retention}.");

        return self::SUCCESS;
    }
}
