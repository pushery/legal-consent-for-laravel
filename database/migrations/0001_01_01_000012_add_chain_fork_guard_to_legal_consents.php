<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make a tamper-chain fork physically impossible.
 *
 * The chain links each row to its predecessor by storing `prev_record_hash`. Two appends for one
 * subject that read the same chain tail (READ COMMITTED, no row lock that covers a not-yet-inserted
 * successor) both write the same `prev_record_hash` — a fork the verifier can only report as
 * tampering, training operators to ignore a real alarm. A unique index on
 * `(subject_token, prev_record_hash)` lets the database reject the second writer; the manager
 * catches that and retries against the now-advanced tail, so the loser chains on cleanly instead of
 * forking (see `DefaultConsentManager::appendChained`).
 *
 * NULLs are distinct in a unique index on all three engines, so this constrains only CHAINED rows
 * (both columns non-null): unchained pre-feature rows (`prev_record_hash IS NULL`) and rows with no
 * token are unaffected, and can still coexist freely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->unique(['subject_token', 'prev_record_hash'], 'legal_consents_chain_link_unique');
        });
    }

    public function down(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->dropUnique('legal_consents_chain_link_unique');
        });
    }
};
