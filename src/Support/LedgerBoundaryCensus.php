<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Exceptions\LedgerCensusDoesNotVerify;

/**
 * How many consent rows lie at or below a ledger boundary, kept beside it.
 *
 * The chain-root boundary exempts the rows at or below it from the root-proof check, and the
 * record-mac boundary from the missing-mac check, because those rows were written before the check
 * existed. Which rows that are was read off the id alone, and an id is chosen by whoever inserts:
 * a row written later with a negative id, into a gap a rolled-back insert left, or under an id the
 * retention sweep freed, landed in the exemption without a key and without touching a marker.
 *
 * Lawful work never adds a row below a boundary. The erasure and a re-link rewrite rows under their
 * own ids, and the retention sweep removes rows and lowers the count by the ones it removed
 * ({@see delete()}). So the census is the count the package accounts for, with a proof over it and
 * the boundary together. More rows there than it names were inserted there, and no id below 1 is
 * ever written by the package at all.
 *
 * What it does not close: a row deleted below a boundary and another inserted in its place leave
 * the count as it was. The deletion itself is what the chain reports, where the deleted row had a
 * successor.
 */
final readonly class LedgerBoundaryCensus
{
    /** The marker name a census is kept under: the boundary marker's name with this suffix. */
    public const string SUFFIX = '_census';

    /** The boundaries a census is kept for. */
    private const array MARKERS = [LedgerHashChain::ROOT_BOUNDARY_MARKER, LedgerRecordMacs::BOUNDARY_MARKER];

    public function __construct(private LedgerHashChain $chain = new LedgerHashChain) {}

    /** Record the count at or below the boundary, in the step that stamps the boundary. */
    public function stamp(string $marker, int $boundaryId): void
    {
        $count = DB::table('legal_consents')->where('id', '<=', $boundaryId)->count();

        DB::table('legal_ledger_markers')->insert([
            'name' => $marker.self::SUFFIX,
            'boundary_id' => $count,
            'proof' => $this->proof($marker, $boundaryId, $count),
            'created_at' => now(),
        ]);
    }

    /** The proof over a census, bound to the boundary it counts below. */
    public function proof(string $marker, int $boundaryId, int $count): string
    {
        return $this->chain->boundaryProof($count, $marker.self::SUFFIX.'|'.$boundaryId);
    }

    /**
     * What is wrong with the census of one boundary, if its marker is there.
     *
     * @return list<string>
     */
    public function breaks(string $marker, string $label): array
    {
        if (! Schema::hasTable('legal_ledger_markers')) {
            return [];
        }

        $boundary = DB::table('legal_ledger_markers')->where('name', $marker)->first();

        if ($boundary === null) {
            return [];
        }

        $boundaryId = is_int($boundary->boundary_id) || is_string($boundary->boundary_id) ? (int) $boundary->boundary_id : 0;
        $census = DB::table('legal_ledger_markers')->where('name', $marker.self::SUFFIX)->first();

        if ($census === null) {
            return ["{$label} boundary #{$boundaryId}: the count of the rows below it is missing — run the package migrations if they are behind (000035 records it); if they ran, it was deleted, and rows below the boundary can no longer be told from rows inserted there"];
        }

        $counted = is_int($census->boundary_id) || is_string($census->boundary_id) ? (int) $census->boundary_id : 0;

        if (! is_string($census->proof) || ! hash_equals($this->proof($marker, $boundaryId, $counted), $census->proof)) {
            return ["{$label} boundary #{$boundaryId}: the count of the rows below it does not verify — it was altered, or this environment holds a different tamper_evidence_key"];
        }

        $now = DB::table('legal_consents')->where('id', '<=', $boundaryId)->count();

        if ($now > $counted) {
            return ["{$label} boundary #{$boundaryId}: {$now} row(s) at or below it, against {$counted} the package accounts for — rows were inserted there with an id of their choosing"];
        }

        return [];
    }

    /**
     * Delete consent rows, lowering each census by the ones removed at or below its boundary.
     *
     * The retention sweep removes rows from below the boundaries as well, and a census left at its
     * old count would leave that many ids free for rows of anyone's choosing. So the count moves
     * with the rows, in the transaction that deletes them, and it moves by the rows deleted rather
     * than to a fresh count: a row somebody slipped in beside the sweep stays one too many.
     *
     * @param  list<int|string>  $ids  as a driver returns them
     * @return int the rows deleted
     *
     * @throws LedgerCensusDoesNotVerify when a census it would lower does not verify, since signing
     *                                   it again would make the count it now holds read as the
     *                                   package's own; nothing is deleted then
     */
    public function delete(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($ids): int {
            $removed = [];

            foreach (DB::table('legal_consents')->whereIn('id', $ids)->lockForUpdate()->pluck('id') as $id) {
                $removed[] = $this->number($id);
            }

            if ($removed === []) {
                return 0;
            }

            $lowered = [];

            foreach ($this->lockedCensuses() as $marker => [$boundaryId, $counted, $proof]) {
                $below = count(array_filter($removed, static fn (int $id): bool => $id <= $boundaryId));

                if ($below === 0) {
                    continue;
                }

                if (! hash_equals($this->proof($marker, $boundaryId, $counted), $proof)) {
                    throw LedgerCensusDoesNotVerify::below($marker, $boundaryId);
                }

                $lowered[$marker] = [$boundaryId, max(0, $counted - $below)];
            }

            $deleted = DB::table('legal_consents')->whereIn('id', $removed)->delete();

            foreach ($lowered as $marker => [$boundaryId, $count]) {
                DB::table('legal_ledger_markers')->where('name', $marker.self::SUFFIX)->update([
                    'boundary_id' => $count,
                    'proof' => $this->proof($marker, $boundaryId, $count),
                ]);
            }

            return $deleted;
        });
    }

    /**
     * Rows with an id below 1, which the package never writes.
     *
     * @return list<string>
     */
    public function impossibleIds(): array
    {
        $count = DB::table('legal_consents')->where('id', '<', 1)->count();

        return $count > 0 ? ["{$count} row(s) with an id below 1 — no write of the package produces one, so they were inserted directly"] : [];
    }

    /**
     * Every boundary that has a census, with the boundary id, the count the census names and its
     * proof. The census row stays locked until the transaction ends, so two sweeps never lower one
     * census from the same count.
     *
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    private function lockedCensuses(): array
    {
        if (! Schema::hasTable('legal_ledger_markers')) {
            return [];
        }

        $censuses = [];

        foreach (self::MARKERS as $marker) {
            $census = DB::table('legal_ledger_markers')->where('name', $marker.self::SUFFIX)->lockForUpdate()->first();
            $boundary = DB::table('legal_ledger_markers')->where('name', $marker)->first();

            if ($census === null || $boundary === null) {
                continue;
            }

            $censuses[$marker] = [$this->number($boundary->boundary_id), $this->number($census->boundary_id), is_string($census->proof) ? $census->proof : ''];
        }

        return $censuses;
    }

    private function number(mixed $value): int
    {
        return is_int($value) || is_string($value) ? (int) $value : 0;
    }
}
