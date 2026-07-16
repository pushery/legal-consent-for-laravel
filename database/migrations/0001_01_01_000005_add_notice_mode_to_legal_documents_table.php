<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the notice mode of a change as first-class data (see Pushery\LegalConsent\
 * Enums\NoticeMode). The old boolean `requires_reconsent` collapsed every legal change
 * into "silent" or "hard-block re-consent"; these columns add the missing info-only and
 * deemed-consent (Zustimmungsfiktion) cases, the per-regime advance-notice period, and
 * the objection deadline. Additive and backward-compatible: `requires_reconsent` stays,
 * and existing rows are backfilled so a notice-mode query is exhaustive from day one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table): void {
            // The mode of this change (NoticeMode::value). Nullable for backward
            // compatibility; the model keeps it in sync with `requires_reconsent`.
            $table->string('notice_mode', 24)->nullable();

            // The legal-review classification tag, e.g. 'agb_minor_peripheral',
            // 'agb_material_core', 'psd2_minor', 'reference_rate', 'dcd_327r_adverse',
            // 'privacy_material', 'privacy_new_purpose', 'consent_scope_change'. Set by a
            // human; never inferred (EuGH C-287/19 gives the standard, not a checklist).
            $table->string('change_class', 48)->nullable();

            // The legal regime that selects notice content + advance-notice period, e.g.
            // 'bgb_agb', 'psd2_675g', 'dcd_327r', 'gdpr', 'p2b', 'eecc'.
            $table->string('regime', 24)->nullable();

            // The advance period actually promised in the notice (audit).
            $table->unsignedInteger('notice_period_days')->nullable();

            // Whether the notice offers a free right to terminate before the effective
            // date (§ 675g / § 327r / P2B).
            $table->boolean('offers_termination')->default(false);

            // Art. 19(4) DCD / § 327r Abs. 4 Nr. 2 escape hatch: the subject may keep the
            // unmodified product, which switches off the notice-and-terminate machinery.
            $table->boolean('keeps_unmodified_offered')->default(false);

            // DeemedConsent only: the end of the objection window. MUST be < enforce_from
            // (enforced at publish). Silence past it is deemed acceptance.
            $table->timestampTz('objection_deadline')->nullable();

            $table->index('notice_mode', 'legal_documents_notice_mode_index');
        });

        // Backfill legacy rows from the boolean so downstream notice-mode queries never
        // miss a pre-existing version. Raw string values (not the enum) — a migration is a
        // historical record and must not depend on evolving app code. Mirrors
        // NoticeMode::fromLegacyReconsent(): true -> active_reconsent, false -> silent_editorial.
        DB::table('legal_documents')->whereNull('notice_mode')->where('requires_reconsent', true)
            ->update(['notice_mode' => 'active_reconsent']);

        DB::table('legal_documents')->whereNull('notice_mode')->where('requires_reconsent', false)
            ->update(['notice_mode' => 'silent_editorial']);
    }

    public function down(): void
    {
        Schema::table('legal_documents', function (Blueprint $table): void {
            $table->dropIndex('legal_documents_notice_mode_index');
            $table->dropColumn([
                'notice_mode',
                'change_class',
                'regime',
                'notice_period_days',
                'offers_termination',
                'keeps_unmodified_offered',
                'objection_deadline',
            ]);
        });
    }
};
