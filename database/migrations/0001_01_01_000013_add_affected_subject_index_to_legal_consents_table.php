<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index `legal_consents (document_key, locale, subject_type, subject_id)` for the re-consent
 * affected-subject sweep.
 *
 * AffectedSubjectResolver::forVersion keyset-pages the subjects who accepted an OLDER major of a
 * document: it filters (document_key, locale[, tenant_id]), then GROUP BY / ORDER BY / seeks on
 * (subject_type, subject_id). Without an index that orders rows that way inside the (document_key,
 * locale) prefix, every page re-scanned the whole document_key range and built a TEMP B-TREE for
 * the GROUP BY (EXPLAIN-confirmed) — a second quadratic term that survived the earlier switch from
 * OFFSET to keyset paging. This index turns the seek into a real index jump (no temp b-tree), so
 * the sweep is linear. tenant_id stays a residual filter, which lets ONE index serve both the
 * tenant and non-tenant paths — placing tenant_id before the sort columns would break the
 * (subject_type, subject_id) ordering whenever tenancy is off. The two hourly notice sweeps drive
 * this resolver, so the cost matters at user-base scale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->index(
                ['document_key', 'locale', 'subject_type', 'subject_id'],
                'legal_consents_affected_subject_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->dropIndex('legal_consents_affected_subject_idx');
        });
    }
};
