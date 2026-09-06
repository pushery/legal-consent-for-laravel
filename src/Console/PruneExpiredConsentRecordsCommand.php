<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Console\Concerns\SkipsWhenTablesAreMissing;
use Pushery\LegalConsent\Contracts\LegalConsentMonitor;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalNotice;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Support\LedgerChainRepair;
use Pushery\LegalConsent\Support\LedgerHashChain;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete consent records past the retention period (default 3 years, § 31 Abs. 2 OWiG /
 * § 195 BGB). DELETE is the one mutation the append-only ledger allows; anonymization of a
 * live record is deliberately NOT offered (it would be an UPDATE, which the ledger blocks).
 * Chunked with per-chunk garbage collection, so peak memory does not grow with the size of the
 * table.
 *
 * DELETING THE SUBJECT DOES NOT ORPHAN THEIR ROWS IN THE SENSE THIS SWEEP MEANS. Removing a
 * person's own record leaves `subject_type` and `subject_id` exactly as they were, so the
 * `is null` arm of the eligibility rule below is never reached that way. Those rows are therefore
 * neither orphaned nor superseded — no newer row will ever arrive for them — and are kept for
 * good, with the ip address and user agent still in them. Stripping the subject from a row IS
 * supported — `Consent::forget($subject)` runs the Art. 17 erasure, and it must run BEFORE the
 * person's own record is deleted, because it needs the model. After it, the `subject_id is null`
 * arm below is reached and these rows are pruned normally.
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
#[AsCommand(name: 'legal-consent:prune')]
final class PruneExpiredConsentRecordsCommand extends Command implements Isolatable
{
    use SkipsWhenTablesAreMissing;

    protected $signature = 'legal-consent:prune';

    protected $description = 'Delete consent and notice records older than the configured retention period.';

    public function handle(LegalConsentMonitor $monitor): int
    {
        DB::disableQueryLog();

        if ($this->tablesAreMissing(['legal_consents', 'legal_notices'])) {
            return self::SUCCESS;
        }

        $retention = config('legal-consent.retention_after_end', '3 years');
        $retention = is_string($retention) && $retention !== '' ? $retention : '3 years';
        $cutoff = CarbonImmutable::now()->modify("-{$retention}");

        $brokenTokens = [];

        $consents = $this->prune(LegalConsent::query(), 'legal_consents', 'accepted_at', $cutoff, $brokenTokens, chained: true);
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
        //
        // The set is STATE-BASED, not run-based, and that is the whole point. Deriving it only from
        // the rows THIS run deletes makes the repair conditional on the run that broke the chain
        // reaching its own last line: kill the process in between — OOM, SIGKILL, host loss, none
        // of which `releaseOnTerminationSignals` catches — and the chain stays broken forever,
        // because the next run has nothing left to delete and therefore nothing to repair. It then
        // reports "Pruned 0" and exits 0 while `legal-consent:verify-ledger` keeps calling a lawful
        // sweep tampering. Adding every chain whose first link does not point at genesis makes each
        // run heal an interrupted predecessor.
        foreach ($this->chainsNotStartingAtGenesis() as $token) {
            $brokenTokens[$token] = true;
        }

        $relinked = $this->relinkBrokenChains(array_keys($brokenTokens));

        // Said out loud, and only when it happened. A line printed on every nightly run is one
        // nobody reads by the second week — and this one is worth reading, because it is the
        // record that a chain was rewritten lawfully rather than by someone else.
        if ($relinked > 0) {
            $this->line("Re-linked the tamper chain for {$relinked} subject(s) whose older rows a retention sweep removed, so a lawful removal does not read as tampering.");
        }

        return self::SUCCESS;
    }

    /**
     * Chains whose first linked row does not point at genesis — a predecessor was removed and
     * nobody re-linked afterwards.
     *
     * The same condition {@see VerifyLedgerCommand} reports as "chain does not start at genesis (a
     * prior row may have been removed)", asked as ONE aggregate instead of a walk: the first
     * chained row per token, joined back to its own row, compared against the public genesis value.
     *
     * WHAT IT DOES NOT SEE, stated because the difference matters: a break in the MIDDLE of a
     * chain whose first row survived. Detecting that means recomputing every row's hash, which is
     * what `legal-consent:verify-ledger` is for and what a nightly delete sweep should not carry.
     * The genesis shape is the one an interrupted sweep actually leaves, because the eligible rows
     * are deleted oldest-first and a token's oldest eligible row is its chain start.
     *
     * @return list<string>
     */
    private function chainsNotStartingAtGenesis(): array
    {
        // `min`, and `max` would pass every test in the suite — measured, not assumed. It is not
        // a hole: a broken chain is flagged either way, because whichever chained row is picked,
        // its link points at a hash rather than at genesis. `max` is worse for a reason no arm can
        // assert, which is why this note exists instead of a test. It flags every INTACT chain of
        // two or more rows as well (the last row's link is a hash by definition), so every
        // multi-row chain in the table enters `relinkBrokenChains()` on every nightly run, loading
        // and re-walking a subject's whole ledger to write nothing — the `$moved` filter below
        // catches it, so the outcome stays correct and only the work is wasted. A test for that
        // would have to assert a query count, which pins the implementation rather than the
        // promise.
        $firstPerToken = DB::table('legal_consents')
            ->selectRaw('subject_token, min(id) as first_id')
            ->whereNotNull('prev_record_hash')
            ->whereNotNull('subject_token')
            ->groupBy('subject_token');

        $rows = DB::query()
            ->fromSub($firstPerToken, 'first_link')
            ->join('legal_consents', 'legal_consents.id', '=', 'first_link.first_id')
            ->where('legal_consents.prev_record_hash', '!=', LedgerHashChain::genesis())
            ->pluck('first_link.subject_token');

        $tokens = [];

        foreach ($rows as $token) {
            if (is_string($token)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
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
            // WRITTEN AS "act when something moved" RATHER THAN "skip when nothing did", and the
            // difference is not style. The skip form leaves a statement no run can reach. Both ways
            // a token reaches this loop guarantee something moves: a removal that leaves survivors
            // always takes a row something after it points at (the newest row per triple is never
            // removed by age alone, and a subject whose rows are ALL gone is handled by the
            // empty-rows branch above), and a chain collected because its first link does not point
            // at genesis is by definition one whose first link has to change. A guard for
            // "nothing moved" would read as a case worth considering and be dead on arrival.
            $moved = array_values(array_filter(
                $corrected,
                fn (array $row, int $index): bool => $row['prev_record_hash'] !== $rows[$index]['prev_record_hash'],
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($moved !== []) {
                DB::transaction(function () use ($moved, $repair): void {
                    DB::table('legal_consents')->whereIn('id', array_column($moved, 'id'))->delete();

                    // Chunked multi-row inserts rather than one statement per row. A subject's
                    // whole re-linked chain used to make one round trip EACH, inside a transaction
                    // the rest of the sweep waits on — and the set is the tail of one subject's
                    // ledger, so it grows with how long that person has been a customer.
                    //
                    // Safe to batch because these are raw arrays, not models: they come from
                    // {@see LedgerChainRepair::toRow()}, so every row carries the identical key set
                    // (which a multi-row insert requires) and there is no `creating` hook or cast
                    // for a batch to bypass. The DELETE still goes first in the same transaction,
                    // so the unique (subject_token, prev_record_hash) index has room for the moved
                    // links whichever way the rows are grouped.
                    //
                    // The chunk is not decoration, and it is not a number written down here
                    // either. It used to be 500 rows, which at 24 columns is 12 000 placeholders —
                    // past a 999-build's ceiling by a factor of twelve, and thirteen times what the
                    // erasure allowed itself for the identical rows. Measured before the repair: a
                    // subject with 47 consent rows produced ONE insert binding 1104 placeholders.
                    // The budget is now derived per row width, in the one place both rewriters read.
                    foreach ($repair->batches($moved) as $batch) {
                        DB::table('legal_consents')->insert($batch);
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
     * @param  literal-string  $table  the ledger's table. It reaches the correlated subquery
     *                                 through the grammar rather than by interpolation, so this is
     *                                 no longer the only thing standing between a caller's value
     *                                 and a statement — but it stays, because a table name is not
     *                                 something a caller should be able to choose either way.
     * @param  array<string, true>  $brokenTokens  out-param, collected BEFORE the delete because
     *                                             afterwards there is nothing left to ask. A set
     *                                             keyed by token, so collecting one is O(1).
     * @param  bool  $chained  whether this ledger carries a chain link at all. Only
     *                         `legal_consents` does; reading the column on `legal_notices` would
     *                         be an error rather than an empty list.
     */
    private function prune(Builder $query, string $table, string $timestamp, CarbonImmutable $cutoff, array &$brokenTokens = [], bool $chained = false): int
    {
        $deleted = 0;

        $query
            ->withoutGlobalScope(TenantScope::class) // retention runs across all tenants
            ->select(['id'])
            ->where($timestamp, '<', $cutoff)
            // BUILT, NOT WRITTEN, and that is the whole repair. This was the package's last
            // hand-assembled runtime statement: a `whereRaw` naming `legal_consents` literally on
            // both sides of the correlation. Everything else on this sweep is builder work, and a
            // builder applies the connection's table prefix itself — so this was the one place the
            // prefix went missing, and it went missing as a raw SQLSTATE about a table that does
            // not exist, at 3am, in a scheduled task, on an application whose schema was correct.
            //
            // Interpolating the prefix by hand would have fixed the symptom and kept the class of
            // defect (plus a validated-prefix guard to make the interpolation safe). Handing the
            // table name to `from()` and `whereColumn()` removes both: the grammar wraps the table,
            // the alias and every correlated column, so there is no string to get wrong and nothing
            // hostile can reach a statement in the first place.
            //
            // The alias is prefixed too, by the same grammar, on both the FROM and the columns that
            // reference it — so the two sides agree by construction rather than by convention.
            ->where(function (Builder $eligible) use ($table): void {
                $eligible
                    // Orphaned: the subject was deleted, so no live relationship remains.
                    ->whereNull('subject_id')
                    ->orWhereNull('subject_type')
                    // Superseded: a newer row exists for the same (subject, document, locale).
                    ->orWhereExists(function (QueryBuilder $newer) use ($table): void {
                        $newer
                            ->selectRaw('1')
                            ->from($table, 'newer')
                            ->whereColumn('newer.subject_type', "{$table}.subject_type")
                            ->whereColumn('newer.subject_id', "{$table}.subject_id")
                            ->whereColumn('newer.document_key', "{$table}.document_key")
                            ->whereColumn('newer.locale', "{$table}.locale")
                            ->whereColumn('newer.id', '>', "{$table}.id");
                    });
            })
            // DOCUMENTATION, NOT MECHANISM — and worth keeping only because that is stated.
            // `chunkById` pages through `forPageAfterId`, which begins by REMOVING any existing
            // order on the chunk column and adding its own ascending one, so deleting this line
            // changes no behavior and no test can see it go. What it says out loud is the
            // oldest-first order the class comment above depends on twice (an interrupted sweep
            // leaves the genesis shape; a token's oldest eligible row is its chain start), and
            // that guarantee comes from the framework rather than from here.
            ->orderBy('id')
            ->chunkById(1000, function (Collection $rows) use ($table, $chained, &$deleted, &$brokenTokens): void {
                $ids = $rows->pluck('id')->all();

                if ($chained) {
                    $tokens = DB::table($table)
                        ->whereIn('id', $ids)
                        ->whereNotNull('prev_record_hash')
                        ->whereNotNull('subject_token')
                        ->distinct()
                        ->pluck('subject_token')
                        ->all();

                    foreach ($tokens as $token) {
                        if (is_string($token)) {
                            // A SET, keyed by the token. Held as a list with an `in_array` guard it
                            // rescanned everything collected so far on every new subject —
                            // quadratic in the number of subjects, and measurably so: 0.17 s at
                            // 10 000 tokens against 2.6 s at 40 000, for pure list scanning with no
                            // database work in it at all.
                            $brokenTokens[$token] = true;
                        }
                    }
                }

                $deleted += DB::table($table)->whereIn('id', $ids)->delete();

                gc_collect_cycles();
            });

        return $deleted;
    }
}
