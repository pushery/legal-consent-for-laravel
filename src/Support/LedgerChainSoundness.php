<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Whether one subject_token's chain still reads as this package wrote it, and may therefore be
 * re-linked after a lawful removal.
 *
 * Re-linking recomputes every link with the configured key and records a fresh mac for each row
 * it moves. Done over a chain somebody changed through SQL, it would turn their rows into
 * correctly keyed ones, and `legal-consent:verify-ledger` would call the result intact. So the
 * chain is checked first, with the rules the verifier applies:
 *
 *  - every link after the first matches the hash of the row before it;
 *  - the first link is either genesis, and then the opening row carries its root proof when one
 *    is required, or it points at a row that is gone, which is what a removal of the chain's
 *    oldest rows leaves behind and what the re-link exists to repair;
 *  - the newest row matches the newest mac recorded for it, and has one when it was written after
 *    macs began;
 *  - both boundary markers verify, neither was deleted, and no rows were inserted below either
 *    ({@see LedgerBoundaryCensus}), since a boundary moved, removed or filled from below by somebody
 *    else exempts what lies below it.
 *
 * A missing head is the one break allowed, and it is allowed on purpose: a sweep that removed a
 * chain's oldest rows and was stopped before it re-linked leaves exactly that, and the next run
 * heals it. A break anywhere else was not left by a lawful removal of this package, because the
 * sweep re-links a chain in the same transaction that removes from it.
 */
final class LedgerChainSoundness
{
    /** @var array{0: LedgerRootBoundary|null}|null the root boundary once read, null before */
    private ?array $rootBoundary = null;

    /** @var array{0: LedgerRootBoundary|null}|null the mac boundary once read, null before */
    private ?array $macBoundary = null;

    private ?bool $markersHold = null;

    public function __construct(
        private readonly LedgerHashChain $chain = new LedgerHashChain,
        private readonly LedgerRecordMacs $macs = new LedgerRecordMacs,
    ) {}

    /**
     * @param  list<stdClass>  $rows  every row of ONE subject_token, in id order, as the driver returns them
     */
    public function sound(array $rows): bool
    {
        if (! $this->markersHold()) {
            return false;
        }

        $chained = array_values(array_filter(
            $rows,
            static fn (stdClass $row): bool => is_string($row->prev_record_hash ?? null) && $row->prev_record_hash !== '',
        ));

        if ($chained === []) {
            return true;
        }

        $opener = $chained[0];

        if ($opener->prev_record_hash === LedgerHashChain::genesis() && ! $this->rootProofHolds($opener)) {
            return false;
        }

        for ($index = 1, $count = count($chained); $index < $count; $index++) {
            $link = $chained[$index]->prev_record_hash;

            if (! is_string($link) || ! hash_equals($this->chain->hashRow($chained[$index - 1]), $link)) {
                return false;
            }
        }

        return $this->tailMacHolds($chained[count($chained) - 1]);
    }

    /** The opening row's root proof, where the key and the boundary ask for one. */
    private function rootProofHolds(stdClass $opener): bool
    {
        $expected = $this->chain->rootProof(is_string($opener->subject_token ?? null) ? $opener->subject_token : '');
        $boundary = $this->rootBoundary();

        if ($expected === null || ! $boundary instanceof LedgerRootBoundary || $this->idOf($opener) <= $boundary->id) {
            return true;
        }

        return is_string($opener->root_proof ?? null) && hash_equals($expected, $opener->root_proof);
    }

    /** The newest row against its newest mac: present above the boundary, and matching wherever it exists. */
    private function tailMacHolds(stdClass $tail): bool
    {
        $id = $this->idOf($tail);
        $mac = $this->macs->newestFor([$id])[$id] ?? null;

        if (is_string($mac)) {
            return hash_equals($this->chain->hashRow($tail), $mac);
        }

        $boundary = $this->macBoundary();

        return ! $boundary instanceof LedgerRootBoundary || $id <= $boundary->id;
    }

    /**
     * Whether both boundary markers and the counts kept beside them read as the package left
     * them, and no row carries an id it never writes. Read and checked once per instance.
     *
     * The retention sweep asks before it removes anything from the consent ledger: a row removed
     * from below a boundary that does not hold would free its id for a row of anyone's choosing,
     * or take with it a row that shows what changed.
     */
    public function markersHold(): bool
    {
        if ($this->markersHold !== null) {
            return $this->markersHold;
        }

        $root = $this->rootBoundary();
        $mac = $this->macBoundary();

        return $this->markersHold = (! $root instanceof LedgerRootBoundary || hash_equals($this->chain->boundaryProof($root->id), $root->proof))
            && (! $mac instanceof LedgerRootBoundary || hash_equals($this->macs->boundaryProof($mac->id), $mac->proof))
            && ! $this->chain->rootBoundaryRemoved()
            && ! $this->macs->boundaryRemoved()
            && $this->censusHolds();
    }

    /** Both censuses, and no row with an id the package never writes. */
    private function censusHolds(): bool
    {
        $census = new LedgerBoundaryCensus($this->chain);

        return $census->breaks(LedgerHashChain::ROOT_BOUNDARY_MARKER, 'chain-root') === []
            && $census->breaks(LedgerRecordMacs::BOUNDARY_MARKER, 'record-mac') === []
            && $census->impossibleIds() === [];
    }

    /** Read once: a run checks many chains against the same marker. */
    private function rootBoundary(): ?LedgerRootBoundary
    {
        if ($this->rootBoundary === null) {
            $marker = Schema::hasTable('legal_ledger_markers') ? DB::table('legal_ledger_markers')->where('name', LedgerHashChain::ROOT_BOUNDARY_MARKER)->first() : null;

            $this->rootBoundary = [$marker === null ? null : new LedgerRootBoundary(
                is_int($marker->boundary_id) || is_string($marker->boundary_id) ? (int) $marker->boundary_id : 0,
                is_string($marker->proof) ? $marker->proof : '',
            )];
        }

        return $this->rootBoundary[0];
    }

    /** Read once, for the same reason. */
    private function macBoundary(): ?LedgerRootBoundary
    {
        return ($this->macBoundary ??= [$this->macs->boundary()])[0];
    }

    private function idOf(stdClass $row): int
    {
        return is_int($row->id) || is_string($row->id) ? (int) $row->id : 0;
    }
}
