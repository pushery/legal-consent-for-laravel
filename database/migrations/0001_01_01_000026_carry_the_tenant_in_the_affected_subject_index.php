<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\IndexName;

/**
 * The affected-subject index gains `tenant_id`, so a multi-tenant installation filters its tenant
 * inside the index rather than against the table. The seek still passes the entries of every tenant
 * within one (document_key, locale), because the column trails the index.
 *
 * Migration 000013 indexed `(document_key, locale, subject_type, subject_id)` and its docblock
 * promises the notice sweep walks the population linearly. `AffectedSubjectResolver` adds
 * `where tenant_id = …` whenever tenancy is enabled — a predicate no index carried, so on a
 * multi-tenant deployment that filter was a residual scan and the promise held only for the
 * single-tenant case.
 *
 * `tenant_id` GOES LAST, AND THE POSITION IS THE DECISION. Leading with it would serve the
 * multi-tenant query and make the index unusable as a prefix for the single-tenant one, which is
 * every other installation — a strict downgrade for the majority to help the minority. Trailing,
 * the four always-present columns still form the usable prefix and the extra predicate is filtered
 * inside the index rather than against the table.
 *
 * The alternative the finding offered — filter `tenant_id` unconditionally — is rejected: rows
 * written while tenancy was off carry `''`, so an installation that enabled it later holds mixed
 * values, and an unconditional filter would hide its own history from the sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->dropIndex(IndexName::existing('legal_consents', 'legal_consents_affected_subject_idx'));
            $table->index(
                ['document_key', 'locale', 'subject_type', 'subject_id', 'tenant_id'],
                IndexName::of('legal_consents_affected_subject_idx'),
            );
        });
    }

    public function down(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->dropIndex(IndexName::existing('legal_consents', 'legal_consents_affected_subject_idx'));
            $table->index(
                ['document_key', 'locale', 'subject_type', 'subject_id'],
                IndexName::of('legal_consents_affected_subject_idx'),
            );
        });
    }
};
