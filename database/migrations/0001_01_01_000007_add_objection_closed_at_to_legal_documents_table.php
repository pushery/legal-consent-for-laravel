<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The watermark for the deemed-consent objection-window sweep
 * (`legal-consent:close-objection-windows`): stamped once a DeemedConsent version's
 * objection window has been closed and silence converted to deemed acceptance. Separate
 * from `notified_at` (the notice-dispatch watermark) because they are two independent
 * sweeps over the same version. Its presence makes the sweep idempotent per version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table): void {
            $table->timestampTz('objection_closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('legal_documents', function (Blueprint $table): void {
            $table->dropColumn('objection_closed_at');
        });
    }
};
