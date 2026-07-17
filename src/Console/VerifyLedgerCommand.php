<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Support\LedgerHashChain;
use stdClass;

/**
 * Verify the optional tamper-evidence hash chain (config `tamper_evidence`). Streams the
 * chained rows once, grouped by subject_token in id order, and checks each row's stored
 * `prev_record_hash` against the recomputed hash of its predecessor. Any edit, deletion,
 * insertion, or reorder within a subject's chain surfaces as a break; a non-zero exit
 * lets CI / a scheduled audit fail loudly.
 *
 * Reads raw rows (DB::table, not the Eloquent model) so the canonical form matches exactly
 * what the writer hashed — casts would change the representation and yield false breaks.
 */
final class VerifyLedgerCommand extends Command
{
    protected $signature = 'legal-consent:verify-ledger';

    protected $description = 'Verify the tamper-evidence hash chain of the consent ledger.';

    public function handle(): int
    {
        if (! filter_var(config('legal-consent.tamper_evidence', false), FILTER_VALIDATE_BOOL)) {
            $this->warn('tamper_evidence is disabled — nothing to verify.');

            return self::SUCCESS;
        }

        DB::disableQueryLog();

        $chain = new LedgerHashChain;
        $genesis = LedgerHashChain::genesis();

        $currentToken = null;
        $expectedPrev = $genesis;
        $subjects = 0;
        $rows = 0;
        /** @var list<string> $breaks */
        $breaks = [];

        DB::table('legal_consents')
            ->whereNotNull('prev_record_hash')
            ->whereNotNull('subject_token')
            ->orderBy('subject_token')
            ->orderBy('id')
            ->lazy()
            ->each(function (stdClass $row) use ($chain, $genesis, &$currentToken, &$expectedPrev, &$subjects, &$rows, &$breaks): void {
                $rows++;

                if ($row->subject_token !== $currentToken) {
                    $currentToken = $row->subject_token;
                    $expectedPrev = $genesis;
                    $subjects++;
                }

                $stored = is_string($row->prev_record_hash ?? null) ? $row->prev_record_hash : '';

                if ($stored !== $expectedPrev) {
                    $reason = $expectedPrev === $genesis
                        ? 'chain does not start at genesis (a prior row may have been removed)'
                        : 'link does not match the previous row (edit, deletion, insertion, or reorder)';
                    $rowId = is_int($row->id) || is_string($row->id) ? (string) $row->id : '?';
                    $token = is_string($row->subject_token) ? $row->subject_token : '?';
                    $breaks[] = "subject_token {$token}, row #{$rowId}: {$reason}";
                }

                $expectedPrev = $chain->hashRow($row);
            });

        // A forged row inserted with prev_record_hash = NULL is skipped by the walk above (it
        // filters on whereNotNull) — so on its own it would read as "intact" while the gate counts
        // it as a valid consent. Once chaining has begun, though, EVERY row the writer appends
        // carries a link, so a NULL-prev row with an id past the first chained row was never
        // written through the manager: it is a direct insert. Pre-feature rows have lower ids and
        // are legitimately unchained, so they are not flagged.
        $firstChainedId = DB::table('legal_consents')->whereNotNull('prev_record_hash')->min('id');
        $unprotected = 0;

        if ($firstChainedId !== null) {
            $suspects = DB::table('legal_consents')
                ->whereNull('prev_record_hash')
                ->where('id', '>', $firstChainedId)
                ->orderBy('id')
                ->get(['id']);

            foreach ($suspects as $suspect) {
                $rowId = is_int($suspect->id) || is_string($suspect->id) ? (string) $suspect->id : '?';
                $breaks[] = "row #{$rowId}: unchained row inserted after tamper-evidence began — a chained ledger has no unchained inserts (direct DB write?)";
            }

            $unprotected = (int) DB::table('legal_consents')
                ->whereNull('prev_record_hash')
                ->where('id', '<=', $firstChainedId)
                ->count();
        }

        if ($breaks === []) {
            $this->info("Ledger chain intact: verified {$rows} chained record(s) across {$subjects} subject(s).");

            if ($unprotected > 0) {
                $this->warn("{$unprotected} row(s) predate tamper-evidence and carry no chain link — unprotected, not a break.");
            }

            // Honesty: with no signed head, deleting a subject's NEWEST row leaves nothing to
            // mismatch, so a tail truncation is not detectable here. Say so rather than let the
            // "intact" line imply a guarantee the chain does not give.
            $this->line('Note: an intact chain proves no naive tampering, not that the ledger is untampered. The hash is unkeyed and the head unsigned, so an actor with table-write access can alter a row and re-chain its successors into a consistent chain, and a tail truncation leaves nothing to mismatch. HMAC-key the hash and/or notarize the head externally to close that gap.');

            return self::SUCCESS;
        }

        $this->error(sprintf('Ledger tamper check FAILED: %d break(s) across %d chained record(s).', count($breaks), $rows));

        foreach (array_slice($breaks, 0, 20) as $break) {
            $this->line("  • {$break}");
        }

        if (count($breaks) > 20) {
            $this->line(sprintf('  … and %d more.', count($breaks) - 20));
        }

        return self::FAILURE;
    }
}
