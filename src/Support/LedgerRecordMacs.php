<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The witness for the row that has none: a per-row mac, held OUTSIDE the append-only ledger.
 *
 * {@see LedgerHashChain} states the gap this closes and why keying could not close it — only the
 * backward LINK is stored, so a chain's newest row can be REPLACED with nothing to mismatch
 * against, and the newest row is the one that says what somebody currently holds.
 *
 * WHAT IS STORED IS THE LINK A SUCCESSOR WOULD CARRY. Nothing else, and that is the whole idea:
 * `hashRow()` over the stored row is precisely the value the NEXT row of that chain writes into
 * its `prev_record_hash`. So every row except the last one is already witnessed by a copy of this
 * exact string sitting one row further on; this class gives the last one the same thing. It
 * publishes no value the ledger does not already publish, and a mac can be read against the
 * successor's link whenever both exist.
 *
 * THE MAC IS TAKEN OVER THE ROW AS THE DRIVER RETURNS IT, NEVER OVER WHAT WAS ABOUT TO BE
 * WRITTEN. That is the invariant the class docblock of {@see LedgerHashChain} states for the chain
 * itself — "both the writer and the verifier hash the SAME database-read representation" — and it
 * is the reason this lives in a second table rather than in a column. A value computed before the
 * INSERT cannot predict the rendering: `accepted_at` comes back from PostgreSQL carrying the
 * connection's offset and from MySQL and SQLite carrying none. A first attempt hashed the
 * pre-save shape, passed the whole SQLite suite, and broke every Postgres engine suite at once.
 *
 * APPEND-ONLY BY USE, not by trigger. A row is never corrected: the two lawful rewriters — an
 * Art. 17 erasure and the retention sweep, both through {@see LedgerChainRepair::relink()} —
 * change a row's link and therefore its hash, and each simply records the new mac beside the old
 * one. {@see newestFor()} reads the last one, so the history of lawful rewrites is kept rather
 * than overwritten, and an operator can see that a row was rewritten and when.
 *
 * WHAT THIS IS WORTH, EXACTLY. Keyed, a table-write attacker cannot produce the mac for a row they
 * substituted, so the replacement is reported. Unkeyed they can compute one — exactly as they can
 * already re-chain the whole ledger, which {@see LedgerHashChain} says in its own words. The mac
 * therefore closes the replacement gap to the same degree the chain closes the edit gap, and to no
 * greater degree. It does NOT close a tail TRUNCATION: a deleted row's mac is indistinguishable
 * from the mac of a row a lawful sweep removed, and `verify-ledger` still says so in its note.
 */
final readonly class LedgerRecordMacs
{
    /** Held outside `legal_consents` because the mac can only be taken after the row is read back. */
    public const string TABLE = 'legal_consent_record_macs';

    /** The marker row recording the id above which a missing mac is a break rather than history. */
    public const string BOUNDARY_MARKER = 'record_mac_boundary';

    public function __construct(private LedgerHashChain $chain = new LedgerHashChain) {}

    /**
     * Whether this installation has the table at all.
     *
     * An install that has not run migration 000031 is not in violation of anything, and the
     * verifier says so out loud instead of reporting a wall of breaks — the same posture, for the
     * same reason, that {@see VerifyLedgerCommand::rootBoundary()} takes for a missing marker.
     */
    public function available(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /**
     * Record the mac of each of these consent rows, READ BACK from the database.
     *
     * The read is the point of the method and is not an optimization detail: the caller holds the
     * attributes it just wrote, and hashing those is the mistake this whole design exists to avoid.
     * Passing ids rather than rows is what makes that impossible to get wrong at a call site.
     *
     * Callers run inside their own transaction (the append, the erasure, the sweep), so the rows
     * are visible to this read and the mac lands or rolls back with the row it describes.
     *
     * `list<mixed>` RATHER THAN `list<int|string>`, AND THE NARROWING HAPPENS HERE. Every caller
     * pulls its ids straight out of database rows — `array_column($rewritten, 'id')` on raw
     * `stdClass` arrays — where the type system knows nothing. Pushing the narrowing out to three
     * call sites would write the same three lines three times, in the places that know least about
     * it; this is the method that talks to the table.
     *
     * A value that is neither an int nor a string cannot address a row, so it is dropped rather
     * than passed to `whereIn`. That fails LOUDLY one step later — the row then has no mac, and
     * the verifier reports exactly that — which is the right direction for a value that cannot
     * occur: `legal_consents.id` is a NOT NULL bigint, so a driver returns an int or a numeric
     * string and nothing else.
     *
     * @param  list<mixed>  $consentIds
     */
    public function record(array $consentIds): void
    {
        $consentIds = array_values(array_filter(
            $consentIds,
            static fn (mixed $id): bool => is_int($id) || is_string($id),
        ));

        if ($consentIds === [] || ! $this->available()) {
            return;
        }

        $this->stampBoundary();

        $macs = [];
        $now = now();

        // Chunked on the read as well as the write: an erasure hands over every row a subject
        // holds, and a `whereIn` of that width is the same placeholder ceiling
        // {@see LedgerChainRepair::batches()} exists for. `select *` for the same reason the
        // verifier keeps it — the 18 proof fields include `user_agent` and `ui_wording_snapshot`.
        foreach (array_chunk($consentIds, LedgerChainRepair::MAX_BOUND_PARAMETERS) as $chunk) {
            foreach (DB::table('legal_consents')->whereIn('id', $chunk)->orderBy('id')->get() as $row) {
                $macs[] = [
                    'consent_id' => $row->id,
                    'mac' => $this->chain->hashRow($row),
                    'created_at' => $now,
                ];
            }
        }

        // Three columns per row, so the chunk is a third of the budget. Derived rather than
        // written down for the same reason `batches()` derives its own: a constant is the number
        // that silently stops meaning what it says when a column is added.
        foreach (array_chunk($macs, max(1, intdiv(LedgerChainRepair::MAX_BOUND_PARAMETERS, 3))) as $batch) {
            DB::table(self::TABLE)->insert($batch);
        }
    }

    /**
     * Record where macs begin, the first time one is written.
     *
     * NOT IN THE MIGRATION, AND THAT IS THE WHOLE POINT OF THE METHOD. Migration 000024 learned
     * this about its own marker and wrote it down; the first version of 000031 stamped at
     * migrate-time anyway. A marker stamped then carries a proof computed with whatever key was
     * configured THEN, and the documented order of operations lets an operator set the secret
     * afterwards — the marker is born holding a hash it can never reproduce, and the verifier
     * reports a permanent break on an untouched ledger. Stamped by the call that writes the first
     * mac, it cannot drift from the key in use.
     *
     * The boundary is `max(id)` as it stands NOW, which includes the row whose mac is about to be
     * written. That row is exempt from the ABSENCE check and from nothing else — it carries a mac,
     * so the mismatch check covers it, and ids only grow, so nothing an attacker writes later can
     * land below the line. The alternative, excluding the rows being recorded, would set the
     * boundary below every untouched row in a ledger whose first macs come from an erasure, and
     * demand a mac from each of them.
     *
     * The `exists()` costs one indexed lookup on a two-row table per consent write. Said out loud
     * rather than optimized away: the obvious cache is a cache of database state, and the write
     * path already does four statements.
     */
    private function stampBoundary(): void
    {
        if (! Schema::hasTable('legal_ledger_markers')) {
            return;
        }

        if (DB::table('legal_ledger_markers')->where('name', self::BOUNDARY_MARKER)->exists()) {
            return;
        }

        $highest = DB::table('legal_consents')->max('id');
        $boundary = is_int($highest) || is_string($highest) ? (int) $highest : 0;

        DB::table('legal_ledger_markers')->insert([
            'name' => self::BOUNDARY_MARKER,
            'boundary_id' => $boundary,
            'proof' => $this->boundaryProof($boundary),
            'created_at' => now(),
        ]);
    }

    /**
     * The newest recorded mac per consent id, for the ids asked about.
     *
     * NEWEST, because a lawful rewrite appends. Ordering by `id` rather than by `created_at`: two
     * macs written in the same second by a sweep and an erasure carry the same timestamp, and the
     * sequence is what actually says which came last.
     *
     * @param  list<int|string>  $consentIds
     * @return array<int, string>
     */
    public function newestFor(array $consentIds): array
    {
        if ($consentIds === [] || ! $this->available()) {
            return [];
        }

        $macs = [];

        foreach (array_chunk($consentIds, LedgerChainRepair::MAX_BOUND_PARAMETERS) as $chunk) {
            $rows = DB::table(self::TABLE)
                ->whereIn('consent_id', $chunk)
                ->orderBy('id')
                ->get(['consent_id', 'mac']);

            // Later rows overwrite earlier ones, which is what makes the ascending order read as
            // "the last one wins" without a second pass or a window function no engine here shares.
            // Keyed by the id as an INT, because the caller's key is one too — a bigint arrives as
            // a numeric string through some connections, and two spellings of one id would mean a
            // recorded mac that no lookup ever finds. Both fallbacks are EQUIVALENT under
            // mutation: `consent_id` and `mac` are NOT NULL columns of this table.
            foreach ($rows as $row) {
                if (is_string($row->mac) && (is_int($row->consent_id) || is_string($row->consent_id))) {
                    $macs[(int) $row->consent_id] = $row->mac;
                }
            }
        }

        return $macs;
    }

    /**
     * Where macs begin: above this id a chain tail with no mac was not written by this package.
     *
     * Null when the marker is absent — an installation that has not run migration 000031. Reading
     * that as "boundary zero" would demand a mac from every chain in a database the feature never
     * reached, which is the shape of guard that gets switched off rather than read.
     */
    public function boundary(): ?LedgerRootBoundary
    {
        if (! Schema::hasTable('legal_ledger_markers')) {
            return null;
        }

        $marker = DB::table('legal_ledger_markers')->where('name', self::BOUNDARY_MARKER)->first();

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
     * The MAC over the boundary id, so the boundary cannot simply be raised past a forgery.
     *
     * Its own payload prefix, so a proof lifted from the chain-root marker does not validate this
     * one. Both markers live in one table under different names, and two values that differ only
     * by which row they sit in are two values an attacker can swap.
     */
    public function boundaryProof(int $boundaryId): string
    {
        return $this->chain->boundaryProof($boundaryId, 'record-mac-boundary');
    }
}
