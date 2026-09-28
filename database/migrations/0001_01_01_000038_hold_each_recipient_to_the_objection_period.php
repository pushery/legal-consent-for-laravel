<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\ProofColumnGuard;

/**
 * The objection period a deemed-consent version owes each recipient, stored when it is published.
 *
 * The publisher checks the period from the announcement to the objection deadline. Silence binds a
 * recipient only if they had that period themselves (§ 308 Nr. 5 BGB: a reasonable period, and the
 * warning at its beginning), so `legal-consent:close-objection-windows` measures it again from the
 * day each recipient's notice was delivered. Stored with the version, the measure is the one the
 * version was published under, not whatever the configuration says on the day the window closes.
 * A version published before this column existed is measured against the configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProofColumnGuard::whileDisarmed(function (): void {
            Schema::table('legal_documents', function (Blueprint $table): void {
                $table->unsignedSmallInteger('objection_min_days')->nullable();
            });
        });
    }

    public function down(): void
    {
        ProofColumnGuard::whileDisarmed(function (): void {
            Schema::table('legal_documents', function (Blueprint $table): void {
                $table->dropColumn('objection_min_days');
            });
        });
    }
};
