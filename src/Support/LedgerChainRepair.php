<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * Re-link a subject's chain after a LAWFUL removal, so the verifier stops reporting one.
 *
 * Two operations remove rows on purpose — the Art. 17 erasure and the retention sweep — and both
 * leave the chain unverifiable if nothing follows them. Deleting a row removes the value its
 * successor's link points at; rewriting a row changes its hash, which is the same thing one step
 * later. Either way `legal-consent:verify-ledger` reports tampering, permanently, on a ledger
 * nobody tampered with — and an operator who cannot tell a lawful sweep from an attack stops
 * reading the alarm, which costs more than the alarm gives.
 *
 * ⚠️ THE COST OF THIS IS REAL AND WAS CHOSEN, NOT OVERLOOKED. A supported re-link path exists now,
 * so "the chain verifies" no longer means "no row was ever rewritten" — it means "no row was
 * rewritten OUTSIDE these two operations", and both of them stamp `subject_erased_at` or are a
 * scheduled sweep that reports what it removed. Against an attacker it changes nothing: without a
 * key the verifier already says in its own output that anyone with table-write access can re-link,
 * and with a key this code needs the same secret they would. It is only reachable from application
 * code, never from SQL.
 *
 * IT IS A PURE FUNCTION OVER ROWS, deliberately. It reads no table and writes none — the callers
 * differ too much for a shared write path (one rewrites every row, the other only the ones whose
 * link moved), and the part worth having in one place is the WALK, which is where the subtlety is.
 */
final readonly class LedgerChainRepair
{
    public function __construct(private LedgerHashChain $chain = new LedgerHashChain) {}

    /**
     * A raw driver row as a string-keyed array.
     *
     * `(array) $row` on a stdClass is typed `array<int|string, mixed>` — a database row never has
     * integer keys, but nothing in the type system says so, and casting it away would be asserting
     * rather than establishing. Rebuilding the array is what actually makes the key type true.
     *
     * @return array<string, mixed>
     */
    public function toRow(object $row): array
    {
        $normalized = [];

        foreach ((array) $row as $column => $value) {
            $normalized[(string) $column] = $value;
        }

        return $normalized;
    }

    /**
     * Correct `prev_record_hash` across one token's rows.
     *
     * @param  list<array<string, mixed>>  $rows  one subject_token's rows, in id order, as they
     *                                            will be stored
     * @return list<array<string, mixed>> the same rows with their links corrected
     */
    public function relink(array $rows): array
    {
        $previousChained = null;

        foreach ($rows as $index => $row) {
            // An UNCHAINED row stays unchained and does not advance the pointer. That mirrors the
            // writer, whose `latestChainedRow()` skips a null link, and the verifier, whose walk
            // filters those rows out entirely — a row that predates tamper-evidence is legitimately
            // outside the chain and giving it a link would make it look like a rewrite.
            if (! is_string($row['prev_record_hash'] ?? null) || $row['prev_record_hash'] === '') {
                continue;
            }

            $row['prev_record_hash'] = $this->chain->linkFor($previousChained);
            $rows[$index] = $row;

            // The hash of the row AS IT WILL BE STORED — taken from the corrected array rather
            // than from what was read, because a row's hash folds in its own link. Skip that and
            // the walk fixes the first link and computes every later one from a value that is
            // about to be overwritten.
            $previousChained = (object) $row;
        }

        return $rows;
    }

    /**
     * How many of these rows carry a chain link at all.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function chainedCount(array $rows): int
    {
        return count(array_filter(
            $rows,
            fn (array $row): bool => is_string($row['prev_record_hash'] ?? null) && $row['prev_record_hash'] !== '',
        ));
    }
}
