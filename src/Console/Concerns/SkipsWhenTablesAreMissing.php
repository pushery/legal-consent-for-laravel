<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console\Concerns;

use Illuminate\Support\Facades\Schema;

/**
 * A sweep on a database that has not been migrated yet reports it and stops.
 *
 * The provider schedules the three sweeps only where their tables exist, so its own registration
 * does not reach them before the first `php artisan migrate`. Everything outside that registration
 * still can: a sweep started by hand, or by a schedule entry the host wrote itself, on an
 * installation that has the package and not yet its tables. Each would otherwise end in a
 * QueryException, which a scheduler reports to the application's error handler on every run.
 *
 * A database that cannot be reached is not answered here: the check throws as the sweep would,
 * which is the loud failure the provider chooses for an outage.
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
