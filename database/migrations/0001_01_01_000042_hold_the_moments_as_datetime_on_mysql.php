<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Support\LedgerRecordMacs;
use Pushery\LegalConsent\Support\MigrationLocks;
use Pushery\SQLens\Attributes\NoSqlOnDriver;
use Pushery\SQLens\Attributes\SqlensIgnore;

/**
 * Hold every moment of the package's tables as DATETIME on MySQL.
 *
 * Laravel creates a `timestampTz` or a `timestamp` column as TIMESTAMP on MySQL, and TIMESTAMP ends
 * at 2038-01-19 03:14:07 UTC: from then on MySQL refuses every row the package writes, a consent
 * included. DATETIME holds the years after it. PostgreSQL's `timestamptz` and SQLite hold them
 * already.
 *
 * MySQL turns a TIMESTAMP into a DATETIME in the session time zone of the connection that runs the
 * change, which is the zone every row has been read in. So each moment keeps the string the driver
 * hands back, and the hash chain, which hashes `accepted_at` as that string, keeps every link.
 * Measured on MySQL 8.0.36 under a session of `+09:00`: `2026-10-19 21:00:00` before the change and
 * after it, with six fractional digits as well. From then on the string no longer moves with the
 * session time zone, so a change of that zone no longer reports the ledger as tampered with.
 *
 * Changing the type copies a table under a shared lock: reads go on, writes wait until the copy is
 * done. Each table is changed in one statement, so it is copied once. How long MySQL waits for the
 * table's metadata lock before it starts is bounded, so a migration that cannot have the lock stops
 * after a few seconds instead of holding every query behind it, and running it again picks up
 * where it stopped: a column that is already DATETIME is changed to what it is.
 *
 * The triggers that freeze the ledger stay where they are. MySQL keeps a table's triggers across a
 * MODIFY, and no column is added or removed, so the columns the proof-column guard enumerates are
 * the same before and after. Unlike a migration that rebuilds a table on SQLite or adds a column,
 * this one therefore does not take the guards off.
 */
return new
#[NoSqlOnDriver('pgsql', reason: 'PostgreSQL holds the years after 2038 in timestamptz already; only MySQL needs DATETIME')]
#[NoSqlOnDriver('sqlite', reason: 'SQLite holds the years after 2038 already; only MySQL needs DATETIME')]
#[SqlensIgnore(['MY.L2.COPY_ALTER_TYPE'], reason: 'TIMESTAMP becomes DATETIME only by a copy, and the copy is the change itself: without it MySQL refuses every write after 2038-01-19 03:14:07 UTC. One statement per table copies each table once, under a shared lock that lets reads go on.')]
class extends Migration
{
    /**
     * The moments of each table, each with whether it may be null, as the migrations before this one
     * create them. None carries a default, a comment or a fractional precision.
     *
     * @var array<string, array<string, bool>>
     */
    private const array COLUMNS = [
        'legal_documents' => [
            'published_at' => true,
            'announce_from' => true,
            'enforce_from' => true,
            'notified_at' => true,
            'objection_deadline' => true,
            'objection_closed_at' => true,
            'created_at' => true,
            'updated_at' => true,
        ],
        'legal_consents' => ['accepted_at' => false, 'created_at' => true, 'subject_erased_at' => true],
        'legal_notices' => ['sent_at' => false, 'delivered_at' => true, 'created_at' => true, 'subject_erased_at' => true],
        'legal_notice_attempts' => ['queued_at' => false, 'failed_at' => true],
        'legal_drafts' => ['created_at' => true, 'updated_at' => true],
        'legal_change_sets' => ['published_at' => true, 'created_at' => true, 'updated_at' => true],
        'legal_change_items' => ['created_at' => true, 'updated_at' => true],
        'legal_ledger_markers' => ['created_at' => true],
        LedgerRecordMacs::TABLE => ['created_at' => true],
    ];

    public function up(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        $prefix = $this->prefix();

        MigrationLocks::bound();
        $this->retypeTheMoments($prefix, 'DATETIME');
    }

    /**
     * Back to TIMESTAMP, refused while any moment lies outside its range. MySQL would refuse the
     * change or turn the moment into zeros, and a zeroed `accepted_at` breaks the link it is part of.
     */
    public function down(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        $prefix = $this->prefix();
        $outside = $this->columnsHoldingAMomentOutsideTimestamp($prefix);

        if ($outside !== []) {
            throw new RuntimeException('refusing to hold the moments as TIMESTAMP again while a column holds one outside its '
                .'range, which ends at 2038-01-19 03:14:07 UTC: '.implode(', ', $outside));
        }

        MigrationLocks::bound();
        $this->retypeTheMoments($prefix, 'TIMESTAMP');
    }

    /** Every table's moments in the type given. */
    private function retypeTheMoments(string $prefix, string $type): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $this->retype($prefix.$table, $columns, $type);
        }
    }

    /**
     * One statement for the table: every moment it holds, in the type given, with the nullability it
     * has. `ALGORITHM=COPY, LOCK=SHARED` is what MySQL does for a change of type anyway, written out
     * so the statement says what it costs.
     *
     * @param  array<string, bool>  $columns
     */
    private function retype(string $table, array $columns, string $type): void
    {
        $clauses = [];

        foreach ($columns as $column => $nullable) {
            $clauses[] = "MODIFY `{$column}` {$type} ".($nullable ? 'NULL' : 'NOT NULL');
        }

        DB::statement("ALTER TABLE `{$table}` ".implode(', ', $clauses).', ALGORITHM=COPY, LOCK=SHARED');
    }

    /**
     * The columns, as `table.column`, that hold a moment TIMESTAMP cannot: after 2038-01-19 03:14:07
     * UTC or before 1970-01-01 00:00:01 UTC. `UNIX_TIMESTAMP()` reads a DATETIME in the session's
     * zone, the one the change back would convert it in, and answers 0 for a moment outside the range
     * on a server before 8.0.28.
     *
     * @return list<string>
     */
    private function columnsHoldingAMomentOutsideTimestamp(string $prefix): array
    {
        $outside = [];

        foreach (self::COLUMNS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                $found = DB::select("SELECT 1 FROM `{$prefix}{$table}` WHERE `{$column}` IS NOT NULL"
                    ." AND (UNIX_TIMESTAMP(`{$column}`) > 2147483647 OR UNIX_TIMESTAMP(`{$column}`) < 1) LIMIT 1");

                if ($found !== []) {
                    $outside[] = "{$table}.{$column}";
                }
            }
        }

        return $outside;
    }

    /**
     * The table prefix, refused before the first statement when it is not a bare identifier
     * fragment, because it is interpolated into the DDL above and MySQL does not roll a half-run
     * migration back.
     */
    private function prefix(): string
    {
        $prefix = DB::connection()->getTablePrefix();

        if (preg_match('/^\w*\z/', $prefix) !== 1) {
            throw new RuntimeException("refusing to build the DDL of the moments for an unexpected table prefix: {$prefix}");
        }

        return $prefix;
    }

    private function isMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
