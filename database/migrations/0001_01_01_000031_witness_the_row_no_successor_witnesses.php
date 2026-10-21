<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\LedgerRecordMacs;
use Pushery\LegalConsent\Support\TableLookup;

/**
 * Give the newest row of every chain the witness it never had.
 *
 * The hole this closes is the second structural gap {@see LedgerHashChain} lists: a row is
 * vouched for by the link its successor stores, and the newest row of a chain has no successor.
 * So it could be replaced — not merely truncated — with nothing to mismatch against, and the
 * newest row is the one that says what somebody currently holds. `verify-ledger` exited 0 over a
 * rewritten acceptance.
 *
 * What is recorded is not a new kind of value: it is exactly the link a successor would carry,
 * `LedgerHashChain::hashRow()` over that row. Nothing new is published either — for every row that
 * does have a successor, that same string already sits in the next row's `prev_record_hash`. The
 * feature is only that the last row now has one too.
 *
 * Why a separate table: a column on `legal_consents` is the obvious shape and it cannot work, for
 * two independent reasons:
 *
 *  1. The mac has to be taken over the row as the database returns it, because that is what the
 *     verifier recomputes from. No value computed before the INSERT can predict the driver's
 *     rendering: `accepted_at` comes back from PostgreSQL as `2026-09-16 18:39:21+02` and from
 *     MySQL and SQLite with no offset at all, so a mac over the pre-save shape fails to verify on
 *     PostgreSQL.
 *  2. Reading the row back means a second statement, and `legal_consents` is append-only — the
 *     trigger from migration 000002 refuses an UPDATE unconditionally on MySQL. There is no second
 *     statement to be had on that table.
 *
 * A row in a table of its own is written after the read, which is the only order in which the two
 * facts above are both satisfiable. It carries the history of lawful rewrites for free: the eraser
 * and the retention sweep re-link rows, which changes their hash, and each simply appends the new
 * mac rather than correcting anything.
 *
 * No append-only trigger, and that is the same call migration 000024 made for the marker.
 * A trigger stops the write; the MAC makes the write useless — and the MAC is the half that still
 * holds when the attacker reaches the database by a path no trigger sits on (a restored backup, a
 * replica, a migration run as the owner role). Keyed, they cannot produce a mac for the row they
 * substituted. Unkeyed they can, exactly as they can already re-chain the whole ledger — the
 * posture is the chain's own, and it is stated rather than implied.
 *
 * The boundary marker is the other half. Without one, deleting a mac would be as good as forging
 * it: a row with no mac would simply not be checked. Above the boundary a missing mac is a break;
 * below it, it is history, because every row already in the table was written before this could
 * exist. That marker carries its own MAC over its id, so it cannot be raised past a forgery — and
 * it is stamped by the first mac rather than by this migration, for the reason spelled out in
 * `up()`.
 *
 * It blesses every row already in the ledger, including a replacement made yesterday. It has
 * to: those rows were never witnessed, so no evidence remains to tell an original from a
 * substitute, and inventing one would be worse than saying so. What it closes is every replacement
 * from here on. A row that carries a mac is checked whatever its id — the boundary gates only the
 * question of whether a missing mac is a finding.
 *
 * Nothing in `legal_consents` is touched, no stored hash moves, and no chain needs re-linking.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! TableLookup::exists(LedgerRecordMacs::TABLE)) {
            Schema::create(LedgerRecordMacs::TABLE, function (Blueprint $blueprint): void {
                $blueprint->id();

                // Not a foreign key, and that is deliberate. The retention sweep and an Art. 17
                // erasure both DELETE from legal_consents, and a cascade would take the evidence
                // with the row — while a restrict would refuse the lawful deletion outright. A mac
                // left pointing at a pruned row proves nothing and costs one row; either FK
                // behavior costs something real.
                $blueprint->unsignedBigInteger('consent_id')->index();

                $blueprint->string('mac', 64);
                $blueprint->timestampTz('created_at')->nullable();
            });
        }

        // The boundary is not stamped here, for the reason migration 000024 gives about its own
        // marker: a marker stamped at migrate-time carries a proof computed with whatever key was
        // configured then, and the documented order of operations lets an operator set
        // `tamper_evidence_key` afterwards. The marker is then born holding an unkeyed hash it can
        // never reproduce again, and `verify-ledger` reports a break for ever on a ledger nobody
        // touched.
        //
        // That the boundary means "where macs begin", which does not depend on a key, is true of
        // the boundary and false of its proof, and the proof is what the verifier recomputes.
        //
        // So {@see LedgerRecordMacs::record()} stamps it, the first time a mac is written. It
        // cannot drift from the key in use, because it is written by the same call that uses it.
    }

    public function down(): void
    {
        DB::table('legal_ledger_markers')->where('name', LedgerRecordMacs::BOUNDARY_MARKER)->delete();

        Schema::dropIfExists(LedgerRecordMacs::TABLE);
    }
};
