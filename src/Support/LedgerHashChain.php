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
 * chain intuitively promises. Two things set the strength: whether a secret KEYS the hash (config
 * `tamper_evidence_key`), and the head is always UNSIGNED (no external anchor).
 *
 * UNKEYED (no `tamper_evidence_key`): the algorithm and the hashed field-set are public (this is a
 * public package) and there is no secret, so an actor with write access to the table — the very
 * threat tamper-evidence is sold against — can edit, insert, reorder, or delete a mid-chain row and
 * then RECOMPUTE `prev_record_hash` for that row and every successor, producing a fully
 * self-consistent chain the verifier reports as intact. Detection then holds only against tampering
 * that does NOT re-chain (a naive edit, an out-of-band `UPDATE`, a partial delete).
 *
 * KEYED (`tamper_evidence_key` set, held OUTSIDE the database): row hashes are HMAC-SHA-256, so a
 * table-write attacker WITHOUT the secret can no longer produce matching links — the re-chain path
 * above is closed for them. An actor who ALSO holds the secret can still re-chain, so keep it off the
 * database host. The secret must be fixed before the first chained row (append-only rows cannot be
 * re-keyed).
 *
 * WHAT KEYING DOES NOT CLOSE — say it plainly, because "keyed" invites more confidence than it earns.
 * Two structural gaps survive any key:
 *  1. The chain ROOT is a public constant. `genesis()` depends on neither the key nor the subject, and
 *     the verifier resets to it at every new `subject_token`. An attacker who can INSERT picks a fresh
 *     token, links to genesis, and has a self-consistent chain the verifier walks as valid — while the
 *     GATE reads by `subject_type`+`subject_id` and never looks at the token, so the fabricated row
 *     counts as a real holding. No re-chaining required, hence no secret required.
 *  2. Only the backward LINK is stored, never a row's own hash. So the newest row of any chain can be
 *     REPLACED (not just truncated) with nothing to mismatch against.
 * Both are closed by storing a per-row MAC and keying the root per subject; until that ships, the
 * append-only DB trigger — extended to restrict INSERT to the application role — is the real defense,
 * and `verify-ledger`'s "intact" means "no evidence of re-chaining", not "authentic".
 *
 * Independent of keying, the UNSIGNED head leaves two limits: it cannot detect deletion of a
 * subject's NEWEST row (a tail truncation — nothing follows it to mismatch); and a row written
 * straight to the table with a NULL `prev_record_hash` opts out of the walk — so the verifier
 * separately flags any unchained row inserted after chaining began, using the first chained row's id
 * as a self-derived watermark. To also anchor the head, periodically notarize the per-subject head
 * hash to an append-only external store; the append-only DB triggers are the primary defense meanwhile.
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

        $payload = $this->canonical($row).'|'.$prev;
        $key = $this->key();

        return $key === null
            ? hash('sha256', $payload)
            : hash_hmac('sha256', $payload, $key);
    }

    /**
     * The HMAC secret keying the chain, or null for the legacy unkeyed hash. Held OUTSIDE the
     * database (config `tamper_evidence_key`, populated from env/KMS), so an actor with only
     * table-write access cannot re-chain. The writer and verifier both read it here, so they stay
     * in step; an existing chain cannot be re-keyed (append-only), so the secret must be fixed
     * before the first chained row.
     */
    private function key(): ?string
    {
        $key = config('legal-consent.tamper_evidence_key');

        return is_string($key) && $key !== '' ? $key : null;
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
