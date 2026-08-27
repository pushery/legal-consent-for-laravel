<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console\Concerns;

use Illuminate\Support\Facades\Schema;

/**
 * A scheduled sweep on a database that has not been migrated yet reports it and stops.
 *
 * The window is real and every installation passes through it: between `composer require` and the
 * first `php artisan migrate` the three sweeps are registered and due, and each one throws a
 * QueryException the scheduler catches and reports to the application's error handler — hourly,
 * with schedule monitoring one tracker entry per run. That is the same noise
 * `LegalConsentServiceProvider`'s `$runsMigrations` gate exists to prevent; it just answers a
 * different question. "Did the consumer DECLINE these tables?" is not "are the tables THERE yet?".
 *
 * The check belongs in the command rather than in the registration, and the provider says why:
 * registering a scheduled task must not touch the database, so the answer cannot be known at boot.
 *
 * Success, not failure. A fresh install is not a defect, and a red scheduled task on the day
 * someone installs the package teaches them to ignore the one that matters later.
 */
trait SkipsWhenTablesAreMissing
{
    /**
     * @param  list<string>  $tables
     */
    private function tablesAreMissing(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("`{$table}` does not exist yet — run `php artisan migrate` to create this package's tables. Nothing was swept.");

                return true;
            }
        }

        return false;
    }
}
