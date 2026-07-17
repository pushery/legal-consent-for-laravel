<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the `users` columns the package used to offer as an optional "hot-path cache"
 * (`terms_accepted_major`, `privacy_accepted_major`, `consent_subject_token`).
 *
 * They were never read. Nothing in the package ever wrote or consulted them, so they could only
 * drift out of step with the append-only ledger they shadowed — and a denormalized copy that
 * disagrees with the proof is worse than no copy, because the disagreement is silent. They also
 * did not address the cost they were justified by: the gate's per-request expense is a global
 * "which versions are enforceable" query, not a per-user lookup, and that is now cached properly.
 *
 * Published under the SAME tag as the old add-migration, so a consumer who ran it can reverse it:
 *
 *     php artisan vendor:publish --tag=legal-consent-users-cache
 *
 * Guarded per column: a no-op for the (near-universal) consumer who never ran the add, and safe on
 * a host with no `users` table at all.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array COLUMNS = ['terms_accepted_major', 'privacy_accepted_major', 'consent_subject_token'];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $present = array_values(array_filter(
            self::COLUMNS,
            static fn (string $column): bool => Schema::hasColumn('users', $column),
        ));

        if ($present === []) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($present): void {
            $table->dropColumn($present);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'terms_accepted_major')) {
                $table->unsignedInteger('terms_accepted_major')->default(0);
            }

            if (! Schema::hasColumn('users', 'privacy_accepted_major')) {
                $table->unsignedInteger('privacy_accepted_major')->default(0);
            }

            if (! Schema::hasColumn('users', 'consent_subject_token')) {
                $table->uuid('consent_subject_token')->nullable();
            }
        });
    }
};
