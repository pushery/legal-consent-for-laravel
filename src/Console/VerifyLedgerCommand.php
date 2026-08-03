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

        // The forged-chain check. The walk above groups by `subject_token` and restarts at genesis on
        // every new one — but the GATE reads a subject by `subject_type` + `subject_id` and never
        // looks at the token at all. Those two different keys for one question are the hole: an
        // attacker who can INSERT invents a fresh token, links it to the public genesis constant, and
        // gets a self-consistent chain the walk happily verifies, while the gate counts the row as a
        // real holding. No re-chaining, so no secret needed — keying the hash cannot close this.
        //
        // What closes it is the invariant SubjectToken already intends but never enforced: ONE
        // subject has exactly ONE token (its docblock: "two tokens for one subject silently defeats
        // the whole point"). This check is structural, so it holds with or without a key, and it sees
        // rows that predate any of it.
        foreach ($this->tokenBindingBreaks() as $break) {
            $breaks[] = $break;
        }

        if ($breaks === []) {
            $this->info("Ledger chain intact: verified {$rows} chained record(s) across {$subjects} subject(s).");

            if ($unprotected > 0) {
                $this->warn("{$unprotected} row(s) predate tamper-evidence and carry no chain link — unprotected, not a break.");
            }

            // Honesty: with no signed head, deleting a subject's NEWEST row leaves nothing to
            // mismatch, so a tail truncation is not detectable here. Say so rather than let the
            // "intact" line imply a guarantee the chain does not give.
            // Honesty, and it has to be CONDITIONAL: the old note claimed "the hash is unkeyed"
            // unconditionally, which is simply false output whenever a key is configured.
            $keyed = is_string(config('legal-consent.tamper_evidence_key')) && config('legal-consent.tamper_evidence_key') !== '';

            // if/else rather than a multi-line ternary, and the reason is measurable: under
            // php-code-coverage 14 the first arm of a ternary whose arms sit on their own lines is
            // reported UNCOVERED even when a test asserts the string it produces. Verified here —
            // the keyed note is exercised by an artisan test that matches on its wording, and the
            // line still counted as missed, which is what kept this file off 100%.
            //
            // Two statements instead of one arm each is the honest fix: it makes the attribution
            // unambiguous rather than suppressing the number, and it reads no worse.
            if ($keyed) {
                $this->line('Note: an intact chain proves no naive tampering and no re-chaining, not that the ledger is untampered. The hash is HMAC-keyed, so editing history requires the secret — but the head is unsigned and each row stores only the link to its predecessor, so a tail truncation and a replacement of a chain'."'".'s newest row remain undetectable here. Restrict INSERT on legal_consents to the application role, and notarize the head externally to close the rest.');
            } else {
                $this->line('Note: an intact chain proves no naive tampering, not that the ledger is untampered. The hash is unkeyed and the head unsigned, so an actor with table-write access can alter a row and re-chain its successors into a consistent chain, and a tail truncation leaves nothing to mismatch. Set legal-consent.tamper_evidence_key to close the re-chain path, and notarize the head externally for the rest.');
            }

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

    /**
     * Breaks in the subject↔token binding: one subject must own exactly one token, and one token
     * must belong to exactly one subject.
     *
     * Deliberately raw and structural — no hashes, no key. Two aggregate queries, so it also covers
     * the two shapes the chain walk cannot see: a fabricated chain under a FRESH token (the walk
     * verifies it happily; here the victim suddenly owns two tokens) and a row written with
     * `subject_token = NULL`, which the walk filters out entirely (`whereNotNull`) — counted here
     * only from the point chaining began, since pre-feature rows legitimately have none.
     *
     * Anonymized rows are exempt: erasure nulls `subject_id` on purpose and the token is what keeps
     * the proof linkable afterwards, so a null subject is not a binding violation.
     *
     * @return list<string>
     */
    private function tokenBindingBreaks(): array
    {
        $breaks = [];

        // One subject, several tokens — the shape a fabricated chain creates.
        $multiToken = DB::table('legal_consents')
            ->selectRaw('subject_type, subject_id, COUNT(DISTINCT subject_token) AS tokens')
            ->whereNotNull('subject_id')
            ->whereNotNull('subject_token')
            ->groupBy('subject_type', 'subject_id')
            ->havingRaw('COUNT(DISTINCT subject_token) > 1')
            ->get();

        foreach ($multiToken as $row) {
            $breaks[] = sprintf(
                'subject %s#%s carries %s distinct subject_tokens — one subject has exactly one token, so a second chain was fabricated for them (the chain walk verifies each token separately and cannot see this)',
                is_string($row->subject_type) ? $row->subject_type : '?',
                is_scalar($row->subject_id) ? (string) $row->subject_id : '?',
                is_scalar($row->tokens) ? (string) $row->tokens : '?',
            );
        }

        // One token, several subjects — a token stolen onto another subject's rows.
        $sharedToken = DB::table('legal_consents')
            ->selectRaw('subject_token, COUNT(DISTINCT subject_id) AS subjects')
            ->whereNotNull('subject_id')
            ->whereNotNull('subject_token')
            ->groupBy('subject_token')
            ->havingRaw('COUNT(DISTINCT subject_id) > 1')
            ->get();

        foreach ($sharedToken as $row) {
            $breaks[] = sprintf(
                'subject_token %s is shared by %s subjects — a token belongs to exactly one subject',
                is_string($row->subject_token) ? substr($row->subject_token, 0, 12).'…' : '?',
                is_scalar($row->subjects) ? (string) $row->subjects : '?',
            );
        }

        // A row with NO token, written after chaining began. The walk filters those out, so without
        // this they are invisible — while the gate, which reads by subject id, still counts them.
        $firstChainedId = DB::table('legal_consents')->whereNotNull('prev_record_hash')->min('id');

        if ($firstChainedId !== null) {
            $tokenless = (int) DB::table('legal_consents')
                ->whereNull('subject_token')
                ->where('id', '>', $firstChainedId)
                ->count();

            if ($tokenless > 0) {
                $breaks[] = "{$tokenless} row(s) written after chaining began carry no subject_token — the chain walk skips them entirely, while the gate still counts them (direct DB write?)";
            }
        }

        return $breaks;
    }
}
