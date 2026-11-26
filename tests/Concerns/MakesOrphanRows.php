<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point a row to a client that does not exist, inside the transaction of
 * RefreshDatabase. The rollback removes the orphan row after the test.
 */
trait MakesOrphanRows
{
    protected function makeOrphan(string $table, int $id, int $clientId): void
    {
        $update = fn () => DB::table($table)->where('id', $id)->update(['client_id' => $clientId]);

        // SQLite ignores "PRAGMA foreign_keys" inside a transaction. A deferred
        // key is checked at commit, and the test never commits.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
            $update();

            return;
        }

        // MySQL does not check the existing rows when the checks come back on.
        Schema::withoutForeignKeyConstraints($update);
    }
}
