<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Support\LedgerBoundaryCensus;
use Pushery\LegalConsent\Support\LedgerHashChain;
use Pushery\LegalConsent\Support\LedgerRecordMacs;

/**
 * Count the rows at or below each ledger boundary that is already stamped.
 *
 * `legal-consent:verify-ledger` exempts the rows below the chain-root and the record-mac boundary
 * from two of its checks, and read "below" off the id alone, which whoever inserts a row chooses.
 * A boundary stamped from now on records how many rows lay at or below it, and the verifier reports
 * more than that as rows inserted there ({@see LedgerBoundaryCensus}). This records the same count
 * for the boundaries an installation already has, as they stand when it runs.
 *
 * With `tamper_evidence_key` set, run it with the key in place: the count carries a proof made with
 * the key, the way the boundary itself does.
 */
return new class extends Migration
{
    private const array MARKERS = [LedgerHashChain::ROOT_BOUNDARY_MARKER, LedgerRecordMacs::BOUNDARY_MARKER];

    public function up(): void
    {
        $census = new LedgerBoundaryCensus;

        foreach (self::MARKERS as $marker) {
            $boundary = DB::table('legal_ledger_markers')->where('name', $marker)->first();

            if ($boundary === null || DB::table('legal_ledger_markers')->where('name', $marker.LedgerBoundaryCensus::SUFFIX)->exists()) {
                continue;
            }

            $census->stamp($marker, is_int($boundary->boundary_id) || is_string($boundary->boundary_id) ? (int) $boundary->boundary_id : 0);
        }
    }

    public function down(): void
    {
        DB::table('legal_ledger_markers')
            ->whereIn('name', array_map(static fn (string $marker): string => $marker.LedgerBoundaryCensus::SUFFIX, self::MARKERS))
            ->delete();
    }
};
