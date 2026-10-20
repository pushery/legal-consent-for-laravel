<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one read under a time limit the database enforces, and answers null when the database stopped it.
 *
 * PHP cannot interrupt a query it is waiting for, so the limit has to sit in the database. PostgreSQL
 * gets it as `statement_timeout` for the read's own transaction, MySQL as the session's
 * `max_execution_time`, which is put back to its earlier value afterwards. Both engines stop the
 * statement and report it, and that report becomes null here. Every other failure is rethrown.
 *
 * SQLite has no statement timeout, so a read with a limit runs to its end there.
 */
final readonly class ReadTimeLimit
{
    /** PostgreSQL's SQLSTATE for a statement it canceled. */
    private const string POSTGRES_CANCELED = '57014';

    /** MySQL's error number for a statement it interrupted at `max_execution_time`. */
    private const int MYSQL_INTERRUPTED = 3024;

    /**
     * @template TResult
     *
     * @param  int|null  $milliseconds  the time the database gets; null runs the read without a limit, and 0 or less does not run it
     * @param  Closure(): TResult  $read
     * @return TResult|null
     */
    public function run(?int $milliseconds, Closure $read): mixed
    {
        if ($milliseconds === null) {
            return $read();
        }

        if ($milliseconds <= 0) {
            return null;
        }

        $driver = DB::connection()->getDriverName();

        try {
            return match ($driver) {
                'pgsql' => $this->onPostgres($milliseconds, $read),
                'mysql' => $this->onMySql($milliseconds, $read),
                default => $read(),
            };
        } catch (QueryException $e) {
            if ($this->stoppedAtTheLimit($driver, $e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * `set_config(…, true)` is `SET LOCAL`: the limit belongs to this transaction and ends with it, so
     * it reaches no other statement, also not on a pooled connection. Inside a transaction of the
     * caller's this one is a savepoint: a canceled read rolls back to it and leaves the caller's
     * transaction usable, and a finished one puts the earlier value back before it is released.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $read
     * @return TResult
     */
    private function onPostgres(int $milliseconds, Closure $read): mixed
    {
        DB::beginTransaction();

        try {
            $previous = DB::scalar("select current_setting('statement_timeout')");
            DB::select("select set_config('statement_timeout', ?, true)", [(string) $milliseconds]);

            $result = $read();

            DB::select("select set_config('statement_timeout', ?, true)", [is_string($previous) ? $previous : '0']);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $result;
    }

    /**
     * MySQL applies `max_execution_time` to read-only selects of the session, so the earlier value is
     * read first and put back however the read ends. Both values are bound as integers, the type MySQL
     * accepts for this variable: a string is refused with error 1232.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $read
     * @return TResult
     */
    private function onMySql(int $milliseconds, Closure $read): mixed
    {
        $previous = DB::scalar('select @@session.max_execution_time');
        DB::statement('set session max_execution_time = ?', [$milliseconds]);

        try {
            return $read();
        } finally {
            DB::statement('set session max_execution_time = ?', [is_numeric($previous) ? (int) $previous : 0]);
        }
    }

    private function stoppedAtTheLimit(string $driver, QueryException $e): bool
    {
        return match ($driver) {
            'pgsql' => $e->getCode() === self::POSTGRES_CANCELED,
            'mysql' => ($e->errorInfo[1] ?? null) === self::MYSQL_INTERRUPTED,
            default => false,
        };
    }
}
