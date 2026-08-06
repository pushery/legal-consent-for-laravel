<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\ChangeSetFreezeGuard;

/**
 * The structured half of a change description: one typed entry per thing that changed.
 *
 * Three of the four notices that motivated this feature are lists, not prose — sub-processors
 * added, one removed, an optional one becoming required. A paragraph cannot carry that in a form
 * anything can render as a table or check for completeness.
 *
 * The four party columns are the facet set EDPB Opinion 22/2024 Rz. 22 expects a controller to
 * disclose when a new processor is engaged: identity, where the data goes, whom to ask, and what
 * they do with it. They are named by their LEGAL FUNCTION rather than "sub-processor", because the
 * same four facts describe a new payment service provider or a new joint controller, and a column
 * called `subprocessor_name` would have to be duplicated for each of them.
 *
 * Deliberately NOT a JSON column. This package has none anywhere, and PostgreSQL's jsonb reorders
 * object keys on write — so a hashed JSON payload would read back different from what was stored
 * and make every load look dirty.
 *
 * `state` is denormalized from the parent so the freeze trigger can decide without a cross-table
 * subquery, which MySQL will not allow inside a BEFORE trigger on the same statement. The price is
 * a value that could drift; the invariant `item.state === set.state` is asserted by its own test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_change_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('change_set_id')->constrained('legal_change_sets')->cascadeOnDelete();
            $table->string('state', 16)->default('draft'); // mirrors the parent — see the docblock
            $table->unsignedInteger('position');

            $table->string('type', 16); // ChangeItemType::value
            $table->string('subject');  // what changed: '§ 7 Haftung', 'Cloudflare, Inc.'
            $table->text('detail')->nullable();

            // EDPB Opinion 22/2024 Rz. 22 — by legal function, never by vendor role.
            $table->string('party_name')->nullable();
            $table->string('party_location')->nullable();
            $table->string('party_contact')->nullable();
            $table->text('purpose')->nullable();

            $table->timestampsTz();

            $table->unique(['change_set_id', 'position']);
            $table->index(['change_set_id', 'state']);
        });

        ChangeSetFreezeGuard::installItems();
    }

    public function down(): void
    {
        ChangeSetFreezeGuard::dropItems();

        Schema::dropIfExists('legal_change_items');
    }
};
