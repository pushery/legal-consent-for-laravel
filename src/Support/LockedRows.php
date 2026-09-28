<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Closure;
use RuntimeException;
use stdClass;

/**
 * The rows of a locking read, once no transaction it waited for has changed them.
 *
 * On PostgreSQL a locking read that waited for another transaction returns the rows its statement
 * started with. A row that transaction appended is missing, and so is one it deleted and wrote
 * again under its own id, as the erasure and the retention sweep do. Read once more, those rows are
 * there, and locked, so a writer that comes later waits. So the read is repeated until two reads
 * agree. MySQL's locking read returns them the first time, and there the second read confirms it.
 */
final class LockedRows
{
    /** Reads after which rows that still change are refused rather than read again. */
    private const int READS = 10;

    /**
     * @param  Closure(): list<stdClass>  $read  a locking read
     * @return list<stdClass>
     */
    public static function settled(Closure $read): array
    {
        $rows = $read();

        for ($reads = 1; $reads < self::READS; $reads++) {
            $again = $read();

            if ($again == $rows) {
                return $again;
            }

            $rows = $again;
        }

        throw new RuntimeException('The ledger rows changed on each of '.self::READS.' locking reads and were left as they are. Try again.');
    }
}
