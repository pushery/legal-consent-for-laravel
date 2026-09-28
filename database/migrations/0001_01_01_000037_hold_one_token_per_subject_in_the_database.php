<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per subject and tenant, holding the subject's token.
 *
 * A subject's token was looked up before the first row that carries it was stored, and nothing in
 * the database said a subject has one token. Two first writes for one subject at the same moment,
 * a consent in a request and a notice in a queue worker, each found no token and minted one of
 * their own. The subject then had two chains that nothing tied together once an erasure had removed
 * the subject reference, and `verify-ledger` reported one of them as forged.
 *
 * Every write that stores a token now takes the subject's row here first and reads the token from
 * it. The primary key makes it one row per subject and tenant, so a second writer waits at the row
 * until the first has committed and then reads the token the first one stored. The row names the
 * subject by two hashes: `lock_key` of the tenant, the subject type and the subject key, which a
 * write takes, and `subject_hash` of the type and key alone, which an erasure deletes by in every
 * tenant. An erasure deletes the subject's rows, and `legal-consent:prune` deletes a row whose
 * token no ledger row carries any more.
 *
 * It is operational state and not evidence, so it carries no append-only guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_subject_tokens', function (Blueprint $table): void {
            $table->string('lock_key', 64)->primary();
            $table->string('subject_hash', 64)->index();

            // Of the type the ledgers use, and on MySQL of the collation migration 000033 gives
            // theirs, because `legal-consent:prune` compares the two.
            $token = $table->uuid('subject_token')->nullable();

            if (DB::connection()->getDriverName() === 'mysql') {
                $token->collation('utf8mb4_0900_bin');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_subject_tokens');
    }
};
