<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * Optional append-only tamper-evidence for the consent ledger (config `tamper_evidence`).
 *
 * Each new row stores `prev_record_hash` = the hash of the subject's immediately-preceding
 * row (or the genesis constant for the subject's first chained row). A row's hash folds in
 * both its immutable content AND its own `prev_record_hash`, so a later edit, insertion,
 * reorder, or a MID-chain deletion that leaves the rest of the chain UNTOUCHED breaks the link
 * and is surfaced by `legal-consent:verify-ledger`.
 *
 * What this does NOT detect — state it plainly, because the guarantee is narrower than a hash
 * chain intuitively promises. The hash is UNKEYED and the head is UNSIGNED: the algorithm and the
 * hashed field-set are public (this is a public package) and there is no secret. So an actor with
 * write access to the table — the very threat tamper-evidence is sold against — can edit, insert,
 * reorder, or delete a mid-chain row and then RECOMPUTE `prev_record_hash` for that row and every
 * successor, producing a fully self-consistent chain the verifier reports as intact. Detection
 * therefore holds only against tampering that does NOT re-chain (a naive edit, an out-of-band
 * `UPDATE`, a partial delete). Two further limits follow from the unsigned head: it cannot detect
 * deletion of a subject's NEWEST row (a tail truncation — nothing follows it to mismatch); and a
 * row written straight to the table with a NULL `prev_record_hash` opts out of the walk — so the
 * verifier separately flags any unchained row inserted after chaining began, using the first
 * chained row's id as a self-derived watermark. To close the re-chain gap, key the hash with an
 * HMAC secret held OUTSIDE the database and/or periodically notarize the per-subject head hash to
 * an append-only external store; the append-only DB triggers are the primary defense meanwhile.
 *
 * Chaining is per SUBJECT (keyed by the stable `subject_token`, which survives
 * anonymization), not global — so it detects unauthorized tampering of a subject's proof
 * without serializing every write on a single global tail. A LEGITIMATE retention prune of
 * a superseded row will therefore show as an expected discontinuity at that point; correlate
 * it with your retention schedule. Both the writer and the verifier compute hashes from the
 * SAME database-read representation, so there is no write-vs-read drift.
 */
final class LedgerHashChain
{
    /** The link value a subject's first chained row points back to. */
    public static function genesis(): string
    {
        return str_repeat('0', 64);
    }

    /**
     * The `prev_record_hash` a NEW row should carry: the hash of the previous row for the
     * same subject, or genesis when the subject has no prior chained row.
     */
    public function linkFor(?object $previousRow): string
    {
        return $previousRow === null ? self::genesis() : $this->hashRow($previousRow);
    }

    /**
     * The chain hash of a stored row = SHA-256 over its canonical immutable content folded
     * with its own stored `prev_record_hash`.
     */
    public function hashRow(object $row): string
    {
        $prev = $row->prev_record_hash ?? null;
        $prev = is_string($prev) && $prev !== '' ? $prev : self::genesis();

        return hash('sha256', $this->canonical($row).'|'.$prev);
    }

    /**
     * Deterministic serialization of the immutable proof fields (never id / created_at /
     * prev_record_hash itself). Unit separator between fields; nulls collapse to ''.
     */
    private function canonical(object $row): string
    {
        $fields = [
            'subject_type', 'subject_id', 'subject_token', 'document_id', 'document_key',
            'document_type', 'document_version', 'document_major_version', 'content_hash',
            'locale', 'ui_wording_snapshot', 'action', 'method', 'source', 'ip_address',
            'user_agent', 'request_id', 'accepted_at',
        ];

        $parts = [];

        foreach ($fields as $field) {
            $value = $row->{$field} ?? null;
            $parts[] = is_scalar($value) ? (string) $value : '';
        }

        return implode("\x1f", $parts);
    }
}
