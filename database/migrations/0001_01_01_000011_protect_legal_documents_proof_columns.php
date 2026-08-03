<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Pushery\LegalConsent\Support\ProofColumnGuard;

/**
 * Make a published `legal_documents` row physically un-editable — the immutability the table's
 * own docblock promised ("Never update a published row") but never enforced. A published version
 * is frozen proof: the exact sanitized text a subject was shown and the hash the ledger snapshots
 * (EDPB 05/2020 Rz. 108). Correcting a legal text is a NEW version, never an in-place edit.
 *
 * A BEFORE UPDATE trigger with an ALLOWLIST: only `is_active` (activation), `updated_at`,
 * `notified_at` (the dispatch sweep) and `objection_closed_at` (the objection sweep) may change
 * after publish; a change to anything else raises.
 *
 * The model's `updating()` hook is the sibling layer for a normal `save()`, but a trigger is
 * required, not optional: both post-insert sweeps write via `saveQuietly()` (which bypasses model
 * events) and a raw `DB::table()->update()` / `psql` never reaches a model — the trigger is the
 * only guard those paths hit. It ships on ALL THREE engines (unlike the append-only siblings,
 * which block every update via the hook and only add a PG/MySQL trigger): here four columns are
 * legitimately mutable, so a column allowlist has no portable hook-only form and SQLite needs its
 * own trigger for the saveQuietly()/raw paths.
 *
 * The trigger SQL itself lives in {@see ProofColumnGuard}, not here, because it must be
 * RE-INSTALLABLE: SQLite cannot alter a column in place, so any later migration that changes one
 * rebuilds the table — and a rebuild keeps the indexes but DROPS the triggers. A guard that only
 * one migration could install would therefore vanish silently the first time a column moved.
 *
 * NOTE for any FUTURE migration that backfills or rewrites a protected column: it must
 * `ProofColumnGuard::drop()`, run the data change, and `ProofColumnGuard::install()` again — the
 * PG guard fails closed against a migration-time UPDATE exactly as it does against runtime
 * tampering (documented in UPGRADE.md). A migration that changes a COLUMN must call
 * `install()` afterwards for the SQLite reason above, whether or not it touched any data.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProofColumnGuard::install();
    }

    public function down(): void
    {
        ProofColumnGuard::uninstall();
    }
};
