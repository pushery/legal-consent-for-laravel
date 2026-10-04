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
use Pushery\LegalConsent\Exceptions\InvalidRetentionPeriod;
use Pushery\LegalConsent\Exceptions\LedgerCensusDoesNotVerify;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalNotice;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Support\LedgerBoundaryCensus;
use Pushery\LegalConsent\Support\LedgerChainRepair;
use Pushery\LegalConsent\Support\LedgerChainSoundness;
use Pushery\LegalConsent\Support\LedgerHashChain;
use Pushery\LegalConsent\Support\LedgerRecordMacs;
use Pushery\LegalConsent\Support\LockedRows;
use Pushery\LegalConsent\Support\RetentionPeriod;
use Pushery\LegalConsent\Support\SubjectToken;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete consent records past the retention period (default 3 years, § 31 Abs. 2 OWiG /
 * § 195 BGB). DELETE is the one mutation the append-only ledger allows; anonymization of a
 * live record is deliberately NOT offered (it would be an UPDATE, which the ledger blocks).
 * Chunked with per-chunk garbage collection, and chained rows are pruned one subject_token at a
 * time, so peak memory does not grow with the size of the table: the sweep holds one chunk of ids,
 * one page of tokens and one subject's ledger at a time.
 *
 * A chain is re-linked after its rows are removed, which recomputes every moved link with the key
 * and records fresh macs. So a chain is checked before anything touches it, and one that no longer
 * verifies as this package wrote it is left alone and reported: re-linking it would give whatever
 * changed it the key's approval. See {@see LedgerChainSoundness}.
 *
 * A consent row below a ledger boundary is removed through {@see LedgerBoundaryCensus::delete()},
 * which lowers the count kept beside the boundary by the rows it removes, so the id a row leaves
 * does not become a place to insert one. Nothing is removed from the consent ledger while a
 * boundary or its count does not hold.
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
 * ledger row for a (tenant, subject, document, locale) — is never pruned by age alone: deleting
 * it would destroy the very Art. 7(1) proof the still-active relationship depends on. Only
 * SUPERSEDED rows (an even newer row exists for the same tenant, subject, document and locale)
 * and ORPHANED rows (the subject was deleted, so no live relationship remains) are eligible.
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

    /**
     * Rows per delete chunk, and chains per page of the repair after the sweep.
     *
     * A chunk binds its ids in one `whereIn`, so it stays under
     * {@see LedgerChainRepair::MAX_BOUND_PARAMETERS}, the budget for SQLite's most conservative
     * builds, as the other sweeps do with the same number.
     */
    private const int CHUNK = 500;

    protected $signature = 'legal-consent:prune';

    protected $description = 'Delete consent and notice records older than the configured retention period.';

    public function handle(LegalConsentMonitor $monitor): int
    {
        DB::disableQueryLog();

        if ($this->tablesAreMissing(['legal_consents', 'legal_notices'])) {
            return self::SUCCESS;
        }

        $retention = RetentionPeriod::configured(config('legal-consent.retention_after_end', '3 years'));

        // Refused before anything is deleted: a value that gives no cutoff in the past would make
        // every superseded record count as expired. The run fails without a heartbeat, which is
        // what a monitor alerts on.
        try {
            $cutoff = RetentionPeriod::cutoff($retention, CarbonImmutable::now());
        } catch (InvalidRetentionPeriod $refusal) {
            $this->error($refusal->getMessage());
            $this->line('Nothing was pruned.');

            return self::FAILURE;
        }

        $soundness = new LedgerChainSoundness;
        $relinked = 0;
        $refused = 0;
        $consents = 0;

        // Checked once, before the first delete. A row removed from below a boundary that does not
        // hold would free its id for a row of anyone's choosing, or take with it the row that
        // shows what changed, so the consent ledger is then left as it is.
        $ledgerHolds = $soundness->markersHold();

        if ($ledgerHolds) {
            try {
                $consents = $this->pruneUnchained(LegalConsent::model()::query(), 'legal_consents', 'accepted_at', $cutoff, chained: true)
                    + $this->pruneChains($cutoff, $soundness, $relinked, $refused);
            } catch (LedgerCensusDoesNotVerify $refusal) {
                // Changed while this run swept: what was removed before it is consistent, since
                // every delete and the count it lowered commit together.
                $this->error($refusal->getMessage());

                return self::FAILURE;
            }
        }

        $notices = $this->pruneUnchained(LegalNotice::model()::query(), 'legal_notices', 'sent_at', $cutoff);

        $this->info("Pruned {$consents} consent record(s) and {$notices} notice record(s) older than {$retention}.");

        // A subject whose last records went leaves their row in the token registry behind, holding
        // a token no record carries any more. Said only when it happened, like the re-link below.
        $released = new SubjectToken()->forgetUnused();

        if ($released > 0) {
            $this->line("Removed {$released} subject(s) from the token registry whose last record was pruned.");
        }

        // The sweep above re-links a chain in the same transaction that removes from it, so it
        // leaves no broken chain behind. This pass heals what a run of an earlier version left: one
        // stopped between a delete and its repair (OOM, SIGKILL, host loss, none of which
        // `releaseOnTerminationSignals` catches) left chains whose first link points at a row that
        // is gone, and nothing left to delete there.
        if ($ledgerHolds) {
            $relinked += $this->relinkChainsNotStartingAtGenesis($cutoff, $soundness, $refused);
        }

        // Said out loud, and only when it happened. A line printed on every nightly run is one
        // nobody reads by the second week — and this one is worth reading, because it is the
        // record that a chain was rewritten lawfully rather than by someone else.
        if ($relinked > 0) {
            $this->line("Re-linked the tamper chain for {$relinked} subject(s) whose older rows a retention sweep removed, so a lawful removal does not read as tampering.");
        }

        if (! $ledgerHolds) {
            $this->error('Left the consent ledger as it is: a ledger boundary, or the count of the records below one, does not verify as this package wrote it, so removing consent records could free their ids for records of anyone\'s choosing or remove the records that show what changed. No consent record was pruned. Run `php artisan legal-consent:verify-ledger` to see what changed.');

            return self::FAILURE;
        }

        // A chain that does not verify was left as it is, expired rows included: re-linking it
        // would record the key's approval of whatever changed it, and deleting from it would
        // destroy what shows the change. That is for a person to look at, so the run fails.
        if ($refused > 0) {
            $this->error("Left the tamper chain of {$refused} subject(s) as it is: it does not verify as this package wrote it, so re-linking it could make a change made outside the package read as a lawful one. Their records were not pruned. Run `php artisan legal-consent:verify-ledger` to see what changed.");

            return self::FAILURE;
        }

        // Report even a zero sweep: this is the one scheduled task whose SILENCE is the failure.
        // A dispatch that stops running leaves visibly missing mail; a prune that stops running
        // just keeps personal data past its retention period, with nothing failing and nobody
        // noticing (Art. 5(1)(e)). The heartbeat is what makes that detectable.
        //
        // Sent last, after the repair, so a beat means the whole task finished: a run that dies
        // in the sweep or in the repair, or leaves a chain it could not verify, misses its beat,
        // which is what a monitor alerts on.
        $monitor->heartbeat('legal-consent:prune', $consents + $notices);

        return self::SUCCESS;
    }

    /**
     * Delete the eligible rows no chain runs through, a chunk of ids at a time.
     *
     * On `legal_notices` that is every eligible row. On `legal_consents` it is the rows without a
     * link or without a token: a row that predates tamper-evidence, or one no chain can reach, so
     * removing it changes no link. The chained rows go through {@see pruneChains()}.
     *
     * @template TModel of LegalConsent|LegalNotice
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $table  the ledger's table, see {@see eligible()}
     * @param  bool  $chained  whether this ledger carries a chain link at all. Only
     *                         `legal_consents` does; reading the column on `legal_notices` would
     *                         be an error rather than an empty list.
     */
    private function pruneUnchained(Builder $query, string $table, string $timestamp, CarbonImmutable $cutoff, bool $chained = false): int
    {
        $deleted = 0;
        $census = $chained ? new LedgerBoundaryCensus : null;

        $this->eligible($query, $table, $timestamp, $cutoff)
            ->when($chained, fn (Builder $unchained): Builder => $unchained->where(
                fn (Builder $outside): Builder => $outside->whereNull('prev_record_hash')->orWhereNull('subject_token'),
            ))
            ->select(['id'])
            ->chunkById(self::CHUNK, function (Collection $rows) use ($table, $census, &$deleted): void {
                $ids = [];

                foreach ($rows->pluck('id') as $id) {
                    if (is_int($id) || is_string($id)) {
                        $ids[] = $id;
                    }
                }

                $deleted += $census instanceof LedgerBoundaryCensus
                    ? $census->delete($ids)
                    : DB::table($table)->whereIn('id', $ids)->delete();

                gc_collect_cycles();
            });

        return $deleted;
    }

    /**
     * Delete the eligible chained rows, one subject_token at a time, and re-link what is left.
     *
     * One token is one transaction: its rows are read and locked, checked, pruned and re-linked
     * before anything else is. So every break in the chain at the moment of the re-link was made
     * by this transaction, a stopped run leaves no half-pruned chain behind, and nothing has to be
     * remembered from one token to the next. The tokens are paged by value, so the sweep holds one
     * page of tokens and one subject's ledger at a time.
     *
     * @param  int  $relinked  out-param: chains re-linked, added to what it holds
     * @param  int  $refused  out-param: chains left alone because they do not verify
     */
    private function pruneChains(CarbonImmutable $cutoff, LedgerChainSoundness $soundness, int &$relinked, int &$refused): int
    {
        $deleted = 0;
        $after = null;

        do {
            $tokens = [];

            $page = $this->eligible(LegalConsent::model()::query(), 'legal_consents', 'accepted_at', $cutoff)
                ->whereNotNull('prev_record_hash')
                ->whereNotNull('subject_token')
                ->when($after !== null, fn (Builder $query): Builder => $query->where('subject_token', '>', $after))
                ->distinct()
                ->orderBy('subject_token')
                ->limit(self::CHUNK)
                ->pluck('subject_token');

            foreach ($page as $token) {
                if (is_string($token)) {
                    $tokens[] = $token;
                }
            }

            foreach ($tokens as $token) {
                $outcome = DB::transaction(fn (): ?array => $this->pruneChain($token, $cutoff, $soundness));

                if ($outcome === null) {
                    $refused++;
                } else {
                    $deleted += $outcome[0];
                    $relinked += $outcome[1] ? 1 : 0;
                }

                $after = $token;
            }

            gc_collect_cycles();
        } while (count($tokens) === self::CHUNK);

        return $deleted;
    }

    /**
     * One token: check the chain, delete its eligible rows, re-link the survivors.
     *
     * The check comes before the delete, so a chain somebody changed outside the package is
     * neither pruned nor re-linked: see {@see LedgerChainSoundness} for what it checks and why a
     * missing head is the one break it allows.
     *
     * @return array{0: int, 1: bool}|null rows deleted and whether a link moved, or null when the chain does not verify
     */
    private function pruneChain(string $token, CarbonImmutable $cutoff, LedgerChainSoundness $soundness): ?array
    {
        $rows = $this->lockedChain($token);

        if (! $soundness->sound($rows)) {
            return null;
        }

        $ids = [];

        $eligible = $this->eligible(LegalConsent::model()::query(), 'legal_consents', 'accepted_at', $cutoff)
            ->where('subject_token', $token)
            ->whereNotNull('prev_record_hash')
            ->pluck('id');

        foreach ($eligible as $id) {
            if (is_int($id) || is_string($id)) {
                $ids[(int) $id] = true;
            }
        }

        $census = new LedgerBoundaryCensus;

        foreach (array_chunk(array_keys($ids), self::CHUNK) as $chunk) {
            $census->delete($chunk);
        }

        $survivors = array_values(array_filter(
            $rows,
            static fn (stdClass $row): bool => ! isset($ids[is_int($row->id) || is_string($row->id) ? (int) $row->id : 0]),
        ));

        return [count($ids), $this->relinkRows($survivors)];
    }

    /**
     * A chain's rows, locked until the transaction ends.
     *
     * Read until two reads agree ({@see LockedRows}): a row a writer appended while this waited for
     * it would otherwise be missing from the re-link, and point at a hash the re-link changes.
     *
     * @return list<stdClass>
     */
    private function lockedChain(string $token): array
    {
        return LockedRows::settled(static fn (): array => array_values(DB::table('legal_consents')
            ->where('subject_token', $token)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all()));
    }

    /**
     * Re-link every chain whose first linked row does not point at genesis, a page at a time.
     *
     * Paged by the id of each chain's first linked row. A re-link keeps that id (the rows are
     * re-inserted under their own), so repairing a page shifts nothing, and every page starts past
     * the last one, so the loop ends even over a chain the repair could not fix. One page is held
     * at a time, so the pass does not grow with the number of broken chains.
     *
     * A chain with an eligible row of its own was already in front of {@see pruneChains()} in this
     * run, and is skipped here so a chain that does not verify is counted once.
     *
     * @param  int  $refused  out-param: chains left alone because they do not verify
     */
    private function relinkChainsNotStartingAtGenesis(CarbonImmutable $cutoff, LedgerChainSoundness $soundness, int &$refused): int
    {
        $relinked = 0;
        $after = 0;

        do {
            $page = $this->chainsNotStartingAtGenesis($after);
            $size = count($page);
            $tokens = [];

            foreach ($page as $chain) {
                if (is_string($chain->subject_token)) {
                    $tokens[] = $chain->subject_token;
                }

                $after = $chain->first_id;
            }

            // Released before the next page is read, so two pages are never held at once.
            unset($page);

            foreach ($tokens as $token) {
                $pruned = $this->eligible(LegalConsent::model()::query(), 'legal_consents', 'accepted_at', $cutoff)
                    ->where('subject_token', $token)
                    ->whereNotNull('prev_record_hash')
                    ->exists();

                if ($pruned) {
                    continue;
                }

                $outcome = DB::transaction(function () use ($token, $soundness): ?bool {
                    $rows = $this->lockedChain($token);

                    return $soundness->sound($rows) ? $this->relinkRows($rows) : null;
                });

                if ($outcome === null) {
                    $refused++;
                } elseif ($outcome) {
                    $relinked++;
                }
            }
        } while ($size === self::CHUNK);

        return $relinked;
    }

    /**
     * One page of the chains whose first linked row does not point at genesis — a predecessor was
     * removed and nobody re-linked afterwards.
     *
     * The same condition {@see VerifyLedgerCommand} reports as "chain does not start at genesis (a
     * prior row may have been removed)", asked as ONE aggregate instead of a walk: the first
     * chained row per token, joined back to its own row, compared against the public genesis value.
     *
     * WHAT IT DOES NOT SEE, stated because the difference matters: a break in the MIDDLE of a
     * chain whose first row survived. Detecting that means recomputing every row's hash, which is
     * what `legal-consent:verify-ledger` is for and what a nightly delete sweep should not carry.
     * The sweep makes such a break when it removes a superseded row behind one that is still
     * current for another document, and it repairs it in the chunk that makes it; only a run
     * killed inside that chunk leaves one this query cannot find.
     *
     * @param  mixed  $after  the first-link id of the last chain on the previous page, or 0
     * @return array<int, stdClass> `first_id` and `subject_token` per chain, ascending by `first_id`
     */
    private function chainsNotStartingAtGenesis(mixed $after): array
    {
        // `min`, not `max`. Either flags a broken chain, because whichever chained row is picked,
        // its link points at a hash rather than at genesis. `max` would flag every intact chain
        // of two or more rows as well (the last row's link is a hash by definition), so every
        // multi-row chain in the table would enter `relinkBrokenChains()` on every run, loading
        // and re-walking a subject's whole ledger to write nothing. The `$moved` filter below
        // would keep the outcome correct; only the work would be wasted.
        $firstPerToken = DB::table('legal_consents')
            ->selectRaw('subject_token, min(id) as first_id')
            ->whereNotNull('prev_record_hash')
            ->whereNotNull('subject_token')
            ->groupBy('subject_token');

        return DB::query()
            ->fromSub($firstPerToken, 'first_link')
            ->join('legal_consents', 'legal_consents.id', '=', 'first_link.first_id')
            ->where('legal_consents.prev_record_hash', '!=', LedgerHashChain::genesis())
            ->where('first_link.first_id', '>', $after)
            ->orderBy('first_link.first_id')
            ->limit(self::CHUNK)
            ->get(['first_link.first_id', 'first_link.subject_token'])
            ->all();
    }

    /**
     * Re-link one token's surviving rows, and say whether a link had to move.
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
     * Runs inside the caller's transaction, which also holds the rows it read.
     *
     * @param  list<stdClass>  $rows  one subject_token's rows, in id order
     */
    private function relinkRows(array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        $repair = new LedgerChainRepair;
        $rows = array_map($repair->toRow(...), $rows);
        $corrected = $repair->relink($rows);

        // Only the rows whose link actually MOVED, so the repair is never churn on an append-only
        // table. The filter and array_values() change nothing in the ledger they leave: an unmoved
        // row deleted and inserted again comes back identical, id included. What the filter saves
        // is the work.
        $moved = array_values(array_filter(
            $corrected,
            static fn (array $row, int $index): bool => $row['prev_record_hash'] !== $rows[$index]['prev_record_hash'],
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($moved === []) {
            return false;
        }

        // The writer stamps the chain-root boundary before the first root proof it writes, and a
        // re-link that makes a survivor the opener of a keyed chain writes one too.
        if ($repair->writesARootProof($rows, $moved)) {
            (new LedgerHashChain)->stampRootBoundary();
        }

        DB::table('legal_consents')->whereIn('id', array_column($moved, 'id'))->delete();

        // Chunked multi-row inserts rather than one statement per row. A subject's whole re-linked
        // chain used to make one round trip EACH, inside a transaction the rest of the sweep waits
        // on — and the set is the tail of one subject's ledger, so it grows with how long that
        // person has been a customer.
        //
        // Safe to batch because these are raw arrays, not models: they come from
        // {@see LedgerChainRepair::toRow()}, so every row carries the identical key set (which a
        // multi-row insert requires) and there is no `creating` hook or cast for a batch to bypass.
        // The DELETE still goes first in the same transaction, so the unique
        // (subject_token, prev_record_hash) index has room for the moved links whichever way the
        // rows are grouped.
        //
        // The chunk is not decoration, and it is not a number written down here either. It used to
        // be 500 rows, which at 24 columns is 12 000 placeholders — past a 999-build's ceiling by a
        // factor of twelve, and thirteen times what the erasure allowed itself for the identical
        // rows. Measured before the repair: a subject with 47 consent rows produced ONE insert
        // binding 1104 placeholders. The budget is now derived per row width, in the one place both
        // rewriters read.
        foreach ($repair->batches($moved) as $batch) {
            DB::table('legal_consents')->insert($batch);
        }

        // A moved link changes the row's hash, so the mac recorded for it no longer describes it —
        // and one of these rows is a chain TAIL, which is the row the mac is the only witness for.
        // Without this the sweep would leave the ledger reporting itself as tampered, permanently,
        // which is precisely the failure the re-link exists to prevent one layer down.
        //
        // APPENDED, not corrected: the store keeps the old mac beside the new one, so the history of
        // lawful rewrites stays readable. Ids survive a rewrite (the rows are re-inserted with their
        // own), which is what lets a mac keep pointing at the row it describes.
        (new LedgerRecordMacs)->record(array_column($moved, 'id'));

        return true;
    }

    /**
     * Eligible = past the cutoff AND (orphaned OR superseded). Orphaned = the subject was
     * deleted (no live relationship remains). Superseded = a newer row exists for the same
     * tenant, subject, document and locale, so this older one is no longer the current standing.
     * The newest row per tenant has no such successor and is kept, even past the cutoff, so an
     * active subject's proof is never destroyed by age. The whole disjunction is parenthesized
     * so it binds under the cutoff, not beside it.
     *
     * The tenant belongs in the comparison because a standing is held per tenant: the same
     * person in two tenants has two relationships, and a newer row in one says nothing about the
     * other. The sweep itself runs across every tenant, which is why the correlation has to say so.
     *
     * A row that is eligible stays eligible while the sweep runs: its superseding row is newer
     * than it, and the newest row of each (tenant, subject, document, locale), which supersedes
     * every other, is never removed. finishedChains() relies on that.
     *
     * @template TModel of LegalConsent|LegalNotice
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $table  the ledger's table. It reaches the correlated subquery
     *                                 through the grammar rather than by interpolation, so this is
     *                                 no longer the only thing standing between a caller's value
     *                                 and a statement — but it stays, because a table name is not
     *                                 something a caller should be able to choose either way.
     * @return Builder<TModel>
     */
    private function eligible(Builder $query, string $table, string $timestamp, CarbonImmutable $cutoff): Builder
    {
        return $query
            ->withoutGlobalScope(TenantScope::class) // retention runs across all tenants
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
                    // Superseded: a newer row exists for the same (tenant, subject, document, locale).
                    ->orWhereExists(function (QueryBuilder $newer) use ($table): void {
                        $newer
                            ->selectRaw('1')
                            ->from($table, 'newer')
                            ->whereColumn('newer.tenant_id', "{$table}.tenant_id")
                            ->whereColumn('newer.subject_type', "{$table}.subject_type")
                            ->whereColumn('newer.subject_id', "{$table}.subject_id")
                            ->whereColumn('newer.document_key', "{$table}.document_key")
                            ->whereColumn('newer.locale', "{$table}.locale")
                            ->whereColumn('newer.id', '>', "{$table}.id");
                    });
            });
    }
}
