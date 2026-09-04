<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\LedgerHashChain;

/**
 * Give a NEW chain a root only a key-holder can produce, and record where "new" begins.
 *
 * The hole this closes: the chain root is a public constant (64 zeros), so an actor with only
 * table-write access can plant a consent nobody granted by inserting a single row for a fresh
 * `subject_token` pointing at it. The verifier resets its expectation to genesis at every new
 * token, and that row — being the last of its own chain — has its hash compared against nothing.
 * `verify-ledger` then exits 0 over a forged ledger.
 *
 * ⚠️ WHY THE ROOT ITSELF IS NOT KEYED, WHICH IS THE OBVIOUS FIX AND DOES NOT WORK HERE.
 * Two independent reasons, both measured rather than reasoned:
 *
 *  1. Every existing first row already stores the constant. A keyed root invalidates all of them,
 *    and correcting them means UPDATE on `legal_consents` — which the append-only trigger from
 *    migration 000002 refuses unconditionally on MySQL. It is why `LedgerSubjectEraser` rewrites
 *    by delete-and-reinsert rather than by update, and why a table-wide backfill is not available.
 *  2. `PruneExpiredConsentRecordsCommand` asks the DATABASE for chains whose first row does not
 *    point at genesis, as a single `!= <constant>` predicate. A per-subject root leaves that
 *    comparison with no value to compare against.
 *
 * So the root stays the constant and the proof moves BESIDE it, into a column of its own. Nothing
 * that reads `genesis()` changes, no stored hash moves, and no existing row is touched.
 *
 * Two things ship here because they are one mechanism:
 *
 *  - `legal_consents.root_proof` — on the first row of a chain, `hmac(key, 'root|<token>')`. Null
 *    on every other row, and null on every row that predates this migration.
 *  - `legal_ledger_markers` — one row recording the highest `legal_consents.id` at this moment.
 *    Below it a missing `root_proof` is history; above it, it is a forgery. The boundary carries
 *    its own MAC, because a boundary an attacker can raise is a boundary that buys nothing: they
 *    would simply move it past their forged row. Recomputing that MAC needs the secret.
 *
 * The marker needs no append-only trigger of its own. A trigger stops the write; the MAC makes the
 * write useless — and the MAC is the half that still holds when the attacker reaches the database
 * by a path no trigger sits on (a restored backup, a replica, a migration run as the owner role).
 *
 * NOT a hashed proof field. `canonical()` walks a fixed list, so a new column is invisible to it
 * and no stored hash moves — the same property migration 000019 relied on, and what lets this ship
 * without re-chaining a single row. Adding a column does not rebuild the table on any engine here,
 * so both ledgers keep their triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('legal_consents', 'root_proof')) {
            Schema::table('legal_consents', function (Blueprint $blueprint): void {
                $blueprint->string('root_proof', 64)->nullable();
            });
        }

        if (! Schema::hasTable('legal_ledger_markers')) {
            Schema::create('legal_ledger_markers', function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->string('name', 64)->unique();
                $blueprint->unsignedBigInteger('boundary_id');
                $blueprint->string('proof', 64);
                $blueprint->timestampTz('created_at')->nullable();
            });
        }

        if (DB::table('legal_ledger_markers')->where('name', LedgerHashChain::ROOT_BOUNDARY_MARKER)->exists()) {
            return;
        }

        // ⚠️ NOTHING IS STAMPED WITHOUT A KEY, AND THE FIRST VERSION OF THIS GOT IT WRONG.
        // It stamped unconditionally, which quietly required the secret to be configured before
        // `migrate` — otherwise the marker was born holding an unkeyed hash, and the day the
        // operator set the key it could never verify again. A permanent break produced by
        // following the documented order of operations is worse than no marker at all.
        //
        // So the marker is stamped by whoever first opens a chain WITH a key (see
        // DefaultConsentManager::appendChained). Migrating with the key already in place still
        // stamps here, which keeps the common case a single step.
        if (! app(LedgerHashChain::class)->isKeyed()) {
            return;
        }

        // The boundary is the ledger as it stands right now. Everything already here was written
        // before a root proof could exist, so it is exempt by construction — and an install that
        // has never written a consent gets 0, which exempts nothing.
        //
        // ⚠️ THIS BLESSES WHATEVER IS ALREADY IN THE TABLE, INCLUDING A FORGERY PLANTED BEFORE
        // TODAY. It has to: there is no evidence left to tell the two apart, and inventing one
        // would be worse than saying so. What it does close is every forgery from here on.
        $highest = DB::table('legal_consents')->max('id');
        $boundary = is_int($highest) || is_string($highest) ? (int) $highest : 0;

        DB::table('legal_ledger_markers')->insert([
            'name' => LedgerHashChain::ROOT_BOUNDARY_MARKER,
            'boundary_id' => $boundary,
            'proof' => app(LedgerHashChain::class)->boundaryProof($boundary),
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_ledger_markers');

        if (Schema::hasColumn('legal_consents', 'root_proof')) {
            Schema::table('legal_consents', function (Blueprint $blueprint): void {
                $blueprint->dropColumn('root_proof');
            });
        }
    }
};
