<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\AffectedSubjectResolver;
use Pushery\LegalConsent\Support\LedgerHashChain;
use Pushery\LegalConsent\Support\LedgerRootBoundary;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;

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
#[AsCommand(name: 'legal-consent:verify-ledger')]
final class VerifyLedgerCommand extends Command
{
    /** Chained rows per keyset page. */
    private const int PAGE = 1000;

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

        // ⚠️ THE BOUNDARY IS CHECKED BEFORE A SINGLE LEDGER ROW IS READ, and it is checked at all
        // because a boundary an attacker can raise exempts whatever they put below it. Recomputing
        // its MAC needs the secret, so a moved boundary is a break rather than a loophole.
        //
        // It doubles as the key's identity: if this fails on an untouched database, the secret this
        // environment holds is not the one the ledger was written with. That is worth knowing
        // FIRST, because the alternative is reading it off a break list that looks like tampering.
        $boundary = $this->rootBoundary();

        if ($boundary instanceof LedgerRootBoundary && ! hash_equals($boundary->proof, $chain->boundaryProof($boundary->id))) {
            $breaks[] = "chain-root boundary marker #{$boundary->id}: proof does not verify — the marker was altered, or this environment holds a different tamper_evidence_key";
        }

        foreach ($this->chainedRows() as $row) {
            $rows++;

            if ($row->subject_token !== $currentToken) {
                $currentToken = $row->subject_token;
                $expectedPrev = $genesis;
                $subjects++;

                // The row that OPENS a chain is the one the constant root cannot vouch for: it has
                // no predecessor whose hash it must reproduce, and being potentially the only row
                // of its chain, nothing later compares against it either. That is the whole of the
                // forgery — one INSERT for a fresh token pointing at 64 public zeros.
                //
                // Only above the boundary, and only when keyed. Below it a missing proof is
                // history and cannot be anything else; unkeyed there is no secret, so demanding a
                // proof would fail every honest install for a guarantee it never bought.
                foreach ($this->rootProofBreak($chain, $row, $boundary) as $break) {
                    $breaks[] = $break;
                }
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
        }

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

        // Hoisted above the branch because BOTH outcomes need it. It used to be computed inside
        // the success arm only, which is why a FAILED run said nothing about the keying mode —
        // see below for why that silence is the expensive half.
        $keyed = is_string(config('legal-consent.tamper_evidence_key')) && config('legal-consent.tamper_evidence_key') !== '';

        // ⚠️ A CHECK THAT DID NOT RUN MUST NOT PASS SILENTLY, and this is the one that can.
        //
        // rootBoundary() returns null when the marker is absent — an installation that never ran
        // migration 000024. Treating that as "boundary zero" would be worse (see rootBoundary():
        // it would demand a proof from every chain in a database the feature never reached), so
        // the null is right. What was wrong is that nothing said it.
        //
        // Measured: with legal_ledger_markers dropped, a fully fabricated chain — fresh token,
        // genesis link, no root proof, for a subject who never consented — verified as "intact"
        // and exited 0, under a note promising that editing history requires the secret. The
        // identical INSERT with the table present is caught. The only difference was the table.
        //
        // Said on BOTH outcomes, because a break list is exactly where an operator would otherwise
        // read the absence of this class as its absence in the data. Only when keyed: unkeyed the
        // check would not run anyway, and the note below already says that guarantee is weaker.
        if ($keyed && ! $boundary instanceof LedgerRootBoundary) {
            $this->warn('The chain-root boundary is NOT stamped, so the root-proof check did not run: a chain opened by a direct insert cannot be detected here. Run the package migrations — 000024 stamps the boundary.');
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

        // ⚠️ A KEYED RUN HAS TWO CAUSES FOR THIS OUTPUT AND THEY DEMAND OPPOSITE RESPONSES, so
        // saying "FAILED" without naming the mode sends the operator down one of them at random.
        // Real tampering is an incident; the wrong secret is a deployment mistake that has touched
        // no row. They are indistinguishable from the break list alone, because a key that does not
        // match reproduces none of the stored links — every chained row mismatches, which is also
        // what a rewritten history looks like.
        //
        // The shape separates them and costs nothing to state: a wrong key breaks EVERY chained
        // row, tampering breaks the ones that were touched. That is the first thing to look at, so
        // it is the first thing this says.
        if ($keyed) {
            $this->line('The chain is HMAC-keyed, so a wrong, rotated or missing legal-consent.tamper_evidence_key produces this same output while no row has been touched. Compare the counts above: a key mismatch breaks EVERY chained record, tampering breaks only the records it reached. Confirm the secret this environment holds before treating this as an incident.');
        }

        return self::FAILURE;
    }

    /**
     * Every chained row, once, in (subject_token, id) order — KEYSET-paged over a pinned ledger.
     *
     * Two things were wrong with `->lazy()`, and only one of them was about speed. Laravel's
     * lazy() pages with `forPage()`, which is LIMIT/OFFSET: page N makes the engine sort and
     * discard N*1000 rows before it yields its own, on a table this package expects to be large.
     *
     * The other is a correctness bug, and it is not exotic. The ledger is append-only and takes
     * rows WHILE this runs; a row whose subject_token sorts before the current window shifts the
     * offset by one, and the next page re-delivers a row the walk has already seen. Tokens are
     * UUIDs, so a concurrent append lands before the window about half the time. On the second
     * sighting the walk is still inside that token, so it compares the row's stored link against
     * the hash of the row ITSELF and reports "link does not match the previous row (edit,
     * deletion, insertion, or reorder)" — a tamper alarm on an untouched ledger, from the one
     * command whose entire value is that an alarm means something.
     *
     * So: a strict `> (lastToken, lastId)` seek — the sort key IS the group key, which is what
     * makes that resumable — plus a snapshot bound at the highest id present when the walk begins,
     * so a concurrent append is out of scope rather than merely out of order. The engine branch is
     * the one {@see AffectedSubjectResolver} already argues for:
     * PostgreSQL and SQLite range-scan the sargable row-value tuple, every other engine keeps the
     * portable OR/tie-break form.
     *
     * `select *` STAYS. The 18 columns {@see LedgerHashChain}
     * folds into a row hash include `user_agent` and `ui_wording_snapshot`; narrowing the list
     * would drop the verifier'."'".'s own inputs to save three columns.
     *
     * @return iterable<int, stdClass>
     */
    private function chainedRows(): iterable
    {
        $highest = DB::table('legal_consents')->whereNotNull('prev_record_hash')->max('id');

        if (! is_numeric($highest)) {
            return;
        }

        $ceiling = (int) $highest;
        $rowValueSeek = in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true);

        $lastToken = null;
        $lastId = null;

        do {
            $query = DB::table('legal_consents')
                ->whereNotNull('prev_record_hash')
                ->whereNotNull('subject_token')
                ->where('id', '<=', $ceiling)
                ->orderBy('subject_token')
                ->orderBy('id')
                ->limit(self::PAGE);

            if ($lastToken !== null) {
                if ($rowValueSeek) {
                    $query->whereRowValues(['subject_token', 'id'], '>', [$lastToken, $lastId]);
                } else {
                    $query->where(function (QueryBuilder $seek) use ($lastToken, $lastId): void {
                        $seek->where('subject_token', '>', $lastToken)
                            ->orWhere(fn (QueryBuilder $tie): QueryBuilder => $tie->where('subject_token', $lastToken)->where('id', '>', $lastId));
                    });
                }
            }

            $page = $query->get();

            foreach ($page as $row) {
                yield $row;
            }

            // ⚠️ THE TWO `instanceof` CHECKS ARE REACHABLE ONLY AT AN EXACT PAGE BOUNDARY, so a
            // run that never lands on one cannot tell them from `true`. `$page->last()` is
            // null only for an EMPTY page, and the loop below re-queries only when the previous
            // page was exactly full -- so a null here needs a chained-row count that is an exact
            // multiple of PAGE. Measured: on an empty ledger the generator is not entered at all,
            // so that cheap case does not reach them either.
            //
            // Not tested, and that is a decision rather than a gap: the fixture would be a
            // thousand chained rows built to hit one boundary, tied to a constant one edit away
            // from moving. The guards stay because the boundary is real and the failure without
            // them is a fatal in a verifier -- the one command whose whole job is to report
            // trouble rather than become it.
            $last = $page->last();
            $lastToken = $last instanceof stdClass ? $last->subject_token : null;
            $lastId = $last instanceof stdClass ? $last->id : null;
        } while ($page->count() === self::PAGE);
    }

    /**
     * The recorded boundary between chains that predate key-bound roots and chains that do not,
     * or null when no marker exists (an installation that has not run migration 000024).
     *
     * A missing marker is not treated as "boundary zero". That reading would demand a root proof
     * from every chain in a database the feature never reached, turning an un-migrated install
     * into a wall of breaks — the shape of guard that gets switched off rather than read.
     */
    private function rootBoundary(): ?LedgerRootBoundary
    {
        if (! Schema::hasTable('legal_ledger_markers')) {
            return null;
        }

        $marker = DB::table('legal_ledger_markers')
            ->where('name', LedgerHashChain::ROOT_BOUNDARY_MARKER)
            ->first();

        if ($marker === null) {
            return null;
        }

        $id = $marker->boundary_id ?? null;
        $proof = $marker->proof ?? null;

        return new LedgerRootBoundary(
            is_int($id) || is_string($id) ? (int) $id : 0,
            is_string($proof) ? $proof : '',
        );
    }

    /**
     * The break, if any, for the row that opens a chain.
     *
     * Returns a list rather than a nullable string so the caller reads the same whether the rule
     * applies or not — a `foreach` over an empty list says "nothing to report here" without a
     * second branch at the call site to get wrong.
     *
     * @return list<string>
     */
    private function rootProofBreak(LedgerHashChain $chain, stdClass $row, ?LedgerRootBoundary $boundary): array
    {
        $token = is_string($row->subject_token ?? null) ? $row->subject_token : '';
        $expected = $chain->rootProof($token);

        // Unkeyed, un-migrated, or a row from before the boundary: nothing is claimed and nothing
        // is checked. Each of the three is a state an honest installation is legitimately in.
        if ($expected === null || ! $boundary instanceof LedgerRootBoundary || $token === '') {
            return [];
        }

        $rowId = is_int($row->id) || is_string($row->id) ? (int) $row->id : 0;

        if ($rowId <= $boundary->id) {
            return [];
        }

        $stored = is_string($row->root_proof ?? null) ? $row->root_proof : '';

        if ($stored !== '' && hash_equals($expected, $stored)) {
            return [];
        }

        $reason = $stored === ''
            ? 'chain opened after the boundary with no root proof — written by something other than this package, or by an actor without the key'
            : 'root proof does not verify — the opening row was not produced with this key';

        return ["subject_token {$token}, row #{$rowId}: {$reason}"];
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

        // The distinct (identity, token) pairs, folded in PHP rather than counted in SQL.
        //
        // `COUNT(DISTINCT a, b)` has no portable spelling — MySQL takes a column list, PostgreSQL
        // wants a row constructor, and SQLite takes neither — and BOTH counts below need more than
        // one column to be right. Reading the pairs and folding them here is the one shape that
        // says the same thing on all three engines.
        //
        // ⚠️ AND THE GROUPING IS COMPARED AS BYTES, because on MySQL it otherwise is not.
        //
        // GROUP BY follows the column's collation, and every collation Laravel configures by
        // default (utf8mb4_unicode_ci, utf8mb4_0900_ai_ci) is case- and accent-insensitive and
        // PAD SPACE. Measured against a real MySQL 8.4: two tokens differing only in case, and
        // `subject_id` '5' against '5 ', each collapse into ONE group — so the fabricated second
        // chain and the stolen token, the two shapes this whole method exists to find, arrive in
        // PHP already merged and are never reported. PHP compares bytes; the two layers disagreed,
        // and the database's answer was the one that reached the fold.
        //
        // CAST(… AS BINARY) restores byte grouping. It is the same repair, for the same reason,
        // that ProofColumnGuard::installMysql() already carries — that one is about `<=>` on a
        // trigger, this one about GROUP BY on a verifier, and both are the collation reading two
        // different values as one.
        //
        // Only MySQL needs it. PostgreSQL's default collation is deterministic, so equality there
        // is byte-wise already, and SQLite compares BINARY unless a column declares otherwise —
        // which is why the defect was invisible in a suite that runs on SQLite.
        $binaryGrouping = DB::connection()->getDriverName() === 'mysql';
        $columns = ['subject_type', 'subject_id', 'tenant_id', 'subject_token'];

        $selected = $binaryGrouping
            ? array_map(
                static fn (string $column): Expression => DB::raw("CAST(`{$column}` AS BINARY) as `{$column}`"),
                $columns,
            )
            : $columns;

        $grouped = $binaryGrouping
            ? array_map(static fn (string $column): Expression => DB::raw("CAST(`{$column}` AS BINARY)"), $columns)
            : $columns;

        $pairs = DB::table('legal_consents')
            ->select($selected)
            ->whereNotNull('subject_id')
            ->whereNotNull('subject_token')
            ->groupBy($grouped)
            ->get();

        /** @var array<string, array{type: string, id: string, tokens: list<string>}> $tokensPerSubject */
        $tokensPerSubject = [];

        /** @var array<string, list<string>> $subjectsPerToken */
        $subjectsPerToken = [];

        foreach ($pairs as $pair) {
            $type = is_string($pair->subject_type) ? $pair->subject_type : '?';
            $id = is_scalar($pair->subject_id) ? (string) $pair->subject_id : '?';
            $tenant = is_scalar($pair->tenant_id) ? (string) $pair->tenant_id : '';
            $token = is_string($pair->subject_token) ? $pair->subject_token : '?';

            // The TENANT belongs in the identity, and leaving it out was the defect. SubjectToken
            // mints per tenant, so the same person legitimately carries a different token in each
            // one — while this query reads through DB::table(), which the tenant scope never
            // touches. A perfectly clean two-tenant ledger therefore reported a fabricated chain,
            // and a multi-tenant installation could never verify green.
            $identity = $type."\0".$id."\0".$tenant;

            $tokensPerSubject[$identity] ??= ['type' => $type, 'id' => $id, 'tokens' => []];
            $tokensPerSubject[$identity]['tokens'][] = $token;

            // The TYPE belongs in the subject, and leaving it out was the other half. A token on
            // App\Models\User#5 and on App\Models\Admin#5 counted as one subject, which is
            // precisely the theft this check names in its own message.
            $subjectsPerToken[$token][] = $type."\0".$id;
        }

        // One subject, several tokens — the shape a fabricated chain creates.
        foreach ($tokensPerSubject as $subject) {
            $distinct = count(array_unique($subject['tokens']));

            if ($distinct > 1) {
                $breaks[] = sprintf(
                    'subject %s#%s carries %d distinct subject_tokens — one subject has exactly one token, so a second chain was fabricated for them (the chain walk verifies each token separately and cannot see this)',
                    $subject['type'],
                    $subject['id'],
                    $distinct,
                );
            }
        }

        // One token, several subjects — a token stolen onto another subject's rows.
        foreach ($subjectsPerToken as $token => $subjects) {
            $distinct = count(array_unique($subjects));

            if ($distinct <= 1) {
                continue;
            }

            $breaks[] = sprintf(
                'subject_token %s is shared by %d subjects — a token belongs to exactly one subject',
                substr($token, 0, 12).'…',
                $distinct,
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
