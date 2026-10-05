<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\IndexName;

/**
 * An index for the reads that know the tenant and the locale but not the document.
 *
 * With tenancy on, the gate's cache reads the active versions of one locale for one tenant, and a
 * flush asks which locales a tenant has. Every other index on `legal_documents` leads with `key`,
 * so neither read can use one as a prefix. PostgreSQL 18 still reaches the rows with a skip scan
 * over the few keys; MySQL has no such plan for these reads and scans the table: with 10 000
 * tenants of 36 rows each, the gate's read goes through all 360 000 rows (106 ms on MySQL 8.4)
 * without this index and looks up its six rows with it.
 *
 * `key` comes last, so the gate's read receives its rows in the order it sorts them by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table): void {
            $table->index(['tenant_id', 'locale', 'is_active', 'key'], IndexName::of('legal_documents_tenant_locale_active_idx'));
        });
    }

    public function down(): void
    {
        Schema::table('legal_documents', function (Blueprint $table): void {
            $table->dropIndex(IndexName::existing('legal_documents', 'legal_documents_tenant_locale_active_idx'));
        });
    }
};
