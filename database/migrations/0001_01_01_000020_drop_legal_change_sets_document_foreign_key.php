<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\ChangeSetFreezeGuard;

/**
 * Remove the `legal_change_sets.document_id` foreign key: its `ON DELETE SET NULL` is an UPDATE of
 * a row the freeze trigger holds immutable, which is the exact combination 000008 removed from
 * `legal_consents` and 000006 refused for `legal_notices`.
 *
 * The three engines answer a referential SET NULL against a BEFORE UPDATE trigger three different
 * ways, and none of them is the one a reader would expect. PostgreSQL executes the action through
 * SPI, so the trigger fires and the whole parent DELETE aborts — retiring a superseded document
 * becomes impossible. MySQL applies the action WITHOUT firing the row trigger, so it silently
 * rewrites a published change description. SQLite has no trigger on the table at all until this
 * release and rewrites it silently too. One schema, three behaviors, and the loudest of them is
 * still wrong.
 *
 * The constraint is not load-bearing. A published change set carries `document_content_hash` and
 * its own frozen `version`, so it identifies the text it describes without the row it points at
 * still existing; `document_id` is a convenience for reading, and its index (000016) stays.
 *
 * Runs on every engine. 000008 could skip SQLite because the driver was thought unable to drop a
 * foreign key; 000014 showed that assumption was already outdated, and Laravel drops it here
 * through a table rebuild. The rebuild is why the freeze guard is re-installed afterwards: SQLite
 * keeps indexes across a rebuild and DROPS TRIGGERS, so leaving it out would take the guard off
 * the table with nothing going red.
 *
 * Idempotent by inspection rather than by driver: 000016 no longer creates the constraint, so on a
 * fresh installation there is nothing to drop and `dropForeign()` would fail on a key that never
 * existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasDocumentForeignKey()) {
            Schema::table('legal_change_sets', function (Blueprint $table): void {
                $table->dropForeign(['document_id']);
            });
        }

        ChangeSetFreezeGuard::install();
    }

    /**
     * Re-adding the constraint restores the defect this migration removes, which is what a down()
     * is for: it returns the schema to the state the previous migration left it in, and the state
     * this one improves on is a real state a real installation was in.
     */
    public function down(): void
    {
        if (! $this->hasDocumentForeignKey()) {
            Schema::table('legal_change_sets', function (Blueprint $table): void {
                $table->foreign('document_id')->references('id')->on('legal_documents')->nullOnDelete();
            });
        }

        ChangeSetFreezeGuard::install();
    }

    private function hasDocumentForeignKey(): bool
    {
        /**
         * The shape is stated rather than re-checked at runtime. Every driver this package
         * supports builds each entry in the same post-processor step — `columns` is always an
         * `explode(',', …)`, so it is always a list of strings — and the `is_array()` guards that
         * used to stand here could not fire on any of them. A branch no run can enter is not a
         * check; it reads like one while hiding the assumption it was meant to state.
         *
         * @var list<array{columns: list<string>}> $foreignKeys
         */
        $foreignKeys = Schema::getForeignKeys('legal_change_sets');

        foreach ($foreignKeys as $foreignKey) {
            if (in_array('document_id', $foreignKey['columns'], true)) {
                return true;
            }
        }

        return false;
    }
};
