<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OPTIONAL, publish-only hot-path cache on the host `users` table. The ledger stays
 * the single source of truth; these columns just let the middleware answer "is this
 * subject current?" on mandatory documents (terms/privacy) without a ledger query
 * on every request. Optional consents never block and are never cached here.
 *
 * Not auto-loaded (it lives outside the package's loadMigrationsFrom path). Publish
 * it into your app only if you want the cache:
 *   php artisan vendor:publish --tag=legal-consent-users-cache
 * It is a no-op when the host has no `users` table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'terms_accepted_major')) {
                $table->unsignedInteger('terms_accepted_major')->nullable();
            }
            if (! Schema::hasColumn('users', 'privacy_accepted_major')) {
                $table->unsignedInteger('privacy_accepted_major')->nullable();
            }
            if (! Schema::hasColumn('users', 'consent_subject_token')) {
                $table->uuid('consent_subject_token')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(array_values(array_filter([
                Schema::hasColumn('users', 'terms_accepted_major') ? 'terms_accepted_major' : null,
                Schema::hasColumn('users', 'privacy_accepted_major') ? 'privacy_accepted_major' : null,
                Schema::hasColumn('users', 'consent_subject_token') ? 'consent_subject_token' : null,
            ])));
        });
    }
};
