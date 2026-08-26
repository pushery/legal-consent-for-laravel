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
use Pushery\LegalConsent\Support\LedgerChainRepair;
use stdClass;

/**
 * Delete consent records past the retention period (default 3 years, § 31 Abs. 2 OWiG /
 * § 195 BGB). DELETE is the one mutation the append-only ledger allows; anonymization of a
 * live record is deliberately NOT offered (it would be an UPDATE, which the ledger blocks).
 * Chunked with per-chunk GC (128 MB budget).
 *
 * ⚠️ THIS USED TO ADD "deleting the subject instead orphans the record", AND THAT IS TRUE
 * REFERENTIALLY BUT NOT IN THE COLUMN. Removing the subject's own row leaves `subject_type` and
 * `subject_id` exactly as they were, so the `is null` arm of the eligibility rule below is never
 * reached that way. A deleted person's rows are therefore neither orphaned nor superseded — no
 * newer row will ever arrive for them — and are kept for good, with the ip address and user agent
 * still in them. That is the opposite of what a retention sweep is for, and closing it needs a
 * supported way to strip the subject from a row, which the ledger does not currently have.
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

        $brokenTokens = [];

        $consents = $this->prune(LegalConsent::query(), 'legal_consents', 'accepted_at', $cutoff, $brokenTokens);
        $notices = $this->prune(LegalNotice::query(), 'legal_notices', 'sent_at', $cutoff);

        // Report even a zero sweep: this is the one scheduled task whose SILENCE is the failure.
        // A dispatch that stops running leaves visibly missing mail; a prune that stops running
        // just keeps personal data past its retention period, with nothing failing and nobody
        // noticing (Art. 5(1)(e)). The heartbeat is what makes that detectable.
        $monitor->heartbeat('legal-consent:prune', $consents + $notices);

        $this->info("Pruned {$consents} consent record(s) and {$notices} notice record(s) older than {$retention}.");

        // REPAIR THE CHAIN THIS SWEEP JUST BROKE. Removing the rows is correct — DELETE is the one
        // mutation the ledger allows and retention is not optional — but a chained row's successor
        // links to it by hash, so once it is gone `legal-consent:verify-ledger` reports a break.
        //
        // Measured before any of this existed: a chain of three verified intact, this sweep
        // removed the two superseded rows and exited 0 with its usual success line, and the next
        // verification failed with "a prior row may have been removed" — permanently, on a ledger
        // nobody had tampered with. An operator who cannot tell a lawful sweep from an attack
        // stops reading the alarm, which is worth more than the alarm.
        //
        // AFTER both ledgers and after every chunk, never inside the loop: a later chunk deleting
        // from the same subject would break exactly what an earlier repair had just fixed.
        $relinked = $this->relinkBrokenChains($brokenTokens);

        // Said out loud, and only when it happened. A line printed on every nightly run is one
        // nobody reads by the second week — and this one is worth reading, because it is the
        // record that a chain was rewritten lawfully rather than by someone else.
        if ($relinked > 0) {
            $this->line("Re-linked the tamper chain for {$relinked} subject(s) whose older rows this sweep removed, so a lawful retention sweep does not read as tampering.");
        }

        return self::SUCCESS;
    }

    /**
     * Re-link every chain this sweep left dangling, and report how many subjects that was.
     *
     * A survivor whose predecessors are gone has to become its chain's new start; one whose
     * mid-chain predecessor is gone has to point at whatever now precedes it. The walk itself
     * lives in {@see LedgerChainRepair} because the Art. 17 erasure needs the identical one, and
     * two copies of a walk this subtle drift apart on the first change to either.
     *
     * Only the rows whose link actually MOVED are rewritten. A sweep that removed a chain's TAIL
     * changes nothing for the rows before it, and rewriting them would be churn on an append-only
     * table — the thing this command is most careful about.
     *
     * Ids are reused rather than reassigned. The verifier flags an unchained row whose id is past
     * the first chained row IN THE WHOLE TABLE, so a rewritten row at a fresh id would land past
     * that watermark and be reported as a direct database write.
     *
     * @param  list<string>|null  $tokens  null on the notice ledger, which carries no chain
     */
    private function relinkBrokenChains(?array $tokens): int
    {
        if ($tokens === null || $tokens === []) {
            return 0;
        }

        $repair = new LedgerChainRepair;
        $relinked = 0;

        foreach ($tokens as $token) {
            $rows = array_values(DB::table('legal_consents')
                ->where('subject_token', $token)
                ->orderBy('id')
                ->get()
                ->map(fn (stdClass $row): array => $repair->toRow($row))
                ->all());

            if ($rows === []) {
                continue;
            }

            $corrected = $repair->relink($rows);

            // Only the rows whose link actually MOVED, so the repair is never churn on an
            // append-only table.
            //
            // ⚠️ WRITTEN AS "act when something moved" RATHER THAN "skip when nothing did", and
            // the difference is not style. The skip form leaves a statement no run can reach: a
            // sweep removes a chained row only when it is superseded or orphaned, the newest row
            // per triple is never removed by age alone, and a subject whose rows are all orphaned
            // loses every one of them — which the empty-rows branch above already handles. So a
            // removal that leaves survivors always takes a row something after it points at, and
            // "nothing moved" cannot happen here. A guard for it would read as a case worth
            // considering and be dead on arrival.
            $moved = array_values(array_filter(
                $corrected,
                fn (array $row, int $index): bool => $row['prev_record_hash'] !== $rows[$index]['prev_record_hash'],
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($moved !== []) {
                DB::transaction(function () use ($moved): void {
                    DB::table('legal_consents')->whereIn('id', array_column($moved, 'id'))->delete();

                    foreach ($moved as $row) {
                        DB::table('legal_consents')->insert($row);
                    }
                });

                $relinked++;
            }
        }

        return $relinked;
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
     * @param  list<string>|null  $brokenTokens  out-param, collected BEFORE the delete because
     *                                           afterwards there is nothing left to ask. Passed
     *                                           only for `legal_consents`: it is the one ledger
     *                                           carrying a chain link, and reading a column
     *                                           `legal_notices` does not have would be an error
     *                                           rather than an empty list.
     */
    private function prune(Builder $query, string $table, string $timestamp, CarbonImmutable $cutoff, ?array &$brokenTokens = null): int
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
            ->chunkById(1000, function (Collection $rows) use ($table, &$deleted, &$brokenTokens): void {
                $ids = $rows->pluck('id')->all();

                if ($brokenTokens !== null) {
                    $tokens = DB::table($table)
                        ->whereIn('id', $ids)
                        ->whereNotNull('prev_record_hash')
                        ->whereNotNull('subject_token')
                        ->distinct()
                        ->pluck('subject_token')
                        ->all();

                    foreach ($tokens as $token) {
                        if (is_string($token) && ! in_array($token, $brokenTokens, true)) {
                            $brokenTokens[] = $token;
                        }
                    }
                }

                $deleted += DB::table($table)->whereIn('id', $ids)->delete();

                gc_collect_cycles();
            });

        return $deleted;
    }
}
