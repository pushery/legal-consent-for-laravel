<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Exceptions\UnhashableProofFieldException;

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
 * it with your retention schedule.
 *
 * THE CHAIN IS BOUND TO THE ENGINE AND THE CONNECTION TIME ZONE IT WAS STARTED ON. Both the
 * writer and the verifier hash the SAME database-read representation, so there is no
 * write-vs-read drift while those two facts hold — and they are facts about the deployment, not
 * about the code. `accepted_at` enters the hash as the string the driver hands back, and that
 * string differs between engines (PostgreSQL renders `timestamptz` with an offset, MySQL and
 * SQLite render none) and moves with the connection's `timezone` setting on PostgreSQL and MySQL.
 * So a dump restored onto the other engine, or a `database.connections.*.timezone` added or
 * changed after the first chained row, invalidates every stored link at once: `verify-ledger`
 * then reports the whole ledger as tampered although nobody touched a row.
 *
 * That is a precondition of the same shape as the HMAC secret above — fix it before the first
 * chained row — and it is stated rather than removed on purpose. Normalizing the instant would
 * change the canonical form of every row already written, on every engine, and the only way back
 * to a verifiable ledger is to re-chain the whole table: rewriting every proof row is exactly the
 * operation this chain exists to make conspicuous, and afterwards nothing can show that the
 * ledger verified BEFORE the change. An engine migration is therefore a deliberate re-chain
 * event, decided by an operator who knows why, not a silent one imposed by a package upgrade.
 */
final class LedgerHashChain
{
    /**
     * The immutable proof fields, in the exact order they are folded into the hash.
     *
     * Order is part of the format: change it and every stored link stops matching. Held against
     * the live schema by a test, so a migration that adds a column to `legal_consents` fails the
     * suite until the column is classified either into this list or into
     * {@see UNHASHED_COLUMNS} — the list was hand-maintained, and `tenant_id` fell out of the
     * proof that way for as long as the column existed.
     *
     * @var list<string>
     */
    public const array PROOF_FIELDS = [
        'subject_type', 'subject_id', 'subject_token', 'document_id', 'document_key',
        'document_type', 'document_version', 'document_major_version', 'content_hash',
        'locale', 'ui_wording_snapshot', 'action', 'method', 'source', 'ip_address',
        'user_agent', 'request_id', 'accepted_at',
    ];

    /**
     * Hashed, but encoded conditionally — see {@see canonical()}. Named separately because it is
     * neither an ordinary proof field nor an exclusion, and a reader has to be able to tell.
     */
    public const string TENANT_FIELD = 'tenant_id';

    /**
     * The columns deliberately OUTSIDE the hash, each for a reason that is not "we forgot":
     *
     *  - `id` is assigned by the database and is preserved verbatim by every lawful rewrite;
     *  - `created_at` is when the row was stored, not what it asserts (`accepted_at` is that);
     *  - `prev_record_hash` is the link itself and is folded in separately by {@see hashRow()};
     *  - `subject_erased_at` records that an Art. 17 erasure rewrote the row, and migration
     *    000019 declares it as not a hashed proof field — it is a trace for the operator, never
     *    a claim the chain vouches for.
     *
     * @var list<string>
     */
    public const array UNHASHED_COLUMNS = ['id', 'created_at', 'prev_record_hash', 'subject_erased_at'];

    /** The link value a subject's first chained row points back to. */
    public static function genesis(): string
    {
        return str_repeat('0', 64);
    }

    /**
     * The `prev_record_hash` a NEW row should carry: the hash of the previous row for the
     * same subject, or genesis when the subject has no prior chained row.
     *
     * @throws UnhashableProofFieldException when the previous row is not a raw database row
     */
    public function linkFor(?object $previousRow): string
    {
        return $previousRow === null ? self::genesis() : $this->hashRow($previousRow);
    }

    /**
     * The chain hash of a stored row = SHA-256 over its canonical immutable content folded
     * with its own stored `prev_record_hash`.
     *
     * Pass the row as the database driver returned it. An Eloquent model is an object too, but its
     * casts turn four proof columns into enums and a date object, and those cannot be hashed — see
     * `canonical()`.
     *
     * @throws UnhashableProofFieldException when a proof field holds a value with no lossless
     *                                       string form (bool, array, object)
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
     * Deterministic serialization of the immutable proof fields ({@see PROOF_FIELDS}, then
     * {@see TENANT_FIELD}; never anything in {@see UNHASHED_COLUMNS}). Each field is emitted
     * self-delimiting: `N` for null, else `S<byte-length>:<value>`. That distinguishes null from ''
     * and length-prefixes every value, so a field containing the old `\x1f` separator can no longer
     * shift boundaries.
     *
     * WHY tenant_id IS HASHED AT ALL, AND WHY IT IS HASHED LIKE THIS. Under multi-tenancy the gate
     * filters on that column ({@see ConsentGate::standingFor()}), so it decides WHETHER a subject
     * holds a document — and while it sat outside the hash, an actor with DELETE+INSERT rights (both
     * of which this table allows on purpose, so retention and Art. 17 erasure can work) could move
     * an acceptance from one tenant into another, leave `prev_record_hash` untouched, and have
     * `verify-ledger` report the chain intact. No secret needed: a keyed hash does not close a gap
     * over a field that is not in the payload.
     *
     * It is appended LAST and only when it names a tenant, so a row in the shared bucket (`''`,
     * which is every row of every single-tenant installation — the column is NOT NULL and defaults
     * to the empty string) produces a canonical string byte-for-byte identical to the one it
     * produced before this field existed. Those chains keep verifying with no operator action. A
     * multi-tenant installation that already has chained rows is the case that must re-chain, and
     * the upgrade notes say so. The conditional encoding costs nothing in strength: the field count
     * before it is fixed and every part is length-prefixed, so a trailing part can only be read as
     * the tenant, and every move — into, out of, or between tenants — changes the payload.
     *
     * WHAT THE INJECTIVITY CLAIM COVERS — it is narrower than "two distinct rows differ", and saying
     * so is the point. The form is injective over the STRING VALUES of the fields: two rows whose
     * fields string-cast differently always produce different canonical strings, so a fabricated row
     * cannot be crafted to hash-collide onto a real one.
     *
     * It deliberately does NOT distinguish values that string-cast identically, because that is what
     * makes the hash stable across PDO drivers for the same stored value: a column returned as `2`
     * by one driver and `'2'` by another has to agree, or every consumer's chain would depend on
     * their driver. It does NOT make the hash stable across ENGINES — a column whose stored value is
     * RENDERED differently (`accepted_at` on PostgreSQL versus MySQL, and either one under a
     * different connection time zone) is a different string here, and the class docblock states that
     * binding. The cost of the choice is that a value with no lossless string form cannot be
     * admitted at all — `false`, an array and an object all cast to `''`, which is itself a
     * legitimate value, so admitting them would put four different rows on one hash. They are
     * REFUSED (UnhashableProofFieldException) rather than folded.
     */
    private function canonical(object $row): string
    {
        $parts = [];

        foreach (self::PROOF_FIELDS as $field) {
            $parts[] = $this->encode($field, $row->{$field} ?? null);
        }

        $tenant = $row->{self::TENANT_FIELD} ?? null;

        if ($tenant !== null && $tenant !== '') {
            $parts[] = $this->encode(self::TENANT_FIELD, $tenant);
        }

        return implode('', $parts);
    }

    /**
     * One field, self-delimiting.
     *
     * @throws UnhashableProofFieldException when the value has no lossless string form
     */
    private function encode(string $field, mixed $value): string
    {
        if ($value === null) {
            return 'N';
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw UnhashableProofFieldException::for($field, $value);
        }

        $string = (string) $value;

        return 'S'.strlen($string).':'.$string;
    }
}
