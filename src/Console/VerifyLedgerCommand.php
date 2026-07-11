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

        if ($breaks === []) {
            $this->info("Ledger chain intact: verified {$rows} chained record(s) across {$subjects} subject(s).");

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
