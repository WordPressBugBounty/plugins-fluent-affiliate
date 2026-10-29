<?php

namespace FluentAffiliate\Framework\Database;

use Throwable;
use PDOException;
use FluentAffiliate\Framework\Support\Str;

trait DetectsConcurrencyErrors
{
    /**
     * Determine if the database gave up on the transaction because of a
     * lock clash: a deadlock, or a lock it waited too long for. Running
     * the whole transaction again may then succeed.
     *
     * @param  \Throwable  $e
     * @return bool
     */
    protected function causedByConcurrencyError(Throwable $e)
    {
        // SQLSTATE 40001, or MySQL's 1213 (deadlock) and 1205 (lock wait
        // timeout), when the exception carries them.
        if ($e instanceof PDOException && in_array((string) $e->getCode(), ['40001', '1213', '1205'], true)) {
            return true;
        }

        return Str::contains($e->getMessage(), [
            'Deadlock found when trying to get lock',
            'Lock wait timeout exceeded; try restarting transaction',
            'WSREP detected deadlock/conflict and aborted the transaction. Try restarting the transaction',
            'The database file is locked',
            'database is locked',
            'database table is locked',
            'A table in the database is locked',
        ]);
    }
}
