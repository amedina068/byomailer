<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finds the rows whose client_id points to no client.
 *
 * The `tenancy:orphans` command uses this class. The migration that adds the
 * client_id foreign keys has its own copy of the query, because a migration
 * must not depend on app code. `ClientForeignKeyTest` makes sure that both
 * count the same rows. A row with a null client_id (a global blacklist entry)
 * is not an orphan.
 */
class TenantOrphans
{
    /**
     * Every table of the app database that has a client_id column.
     *
     * With no schema, MySQL lists the tables of each database that the user
     * can read. The current schema keeps the list to the app database.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return collect(Schema::getTableListing(Schema::getCurrentSchemaListing(), schemaQualified: false))
            ->filter(fn (string $table): bool => Schema::hasColumn($table, 'client_id'))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The orphan count of each given table. Tables with no orphans are left out.
     *
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    public static function counts(array $tables): array
    {
        return collect($tables)
            ->mapWithKeys(fn (string $table): array => [$table => self::query($table)->count()])
            ->filter()
            ->all();
    }

    /**
     * The client ids that the orphan rows of the table point to.
     *
     * @return list<int>
     */
    public static function missingClientIds(string $table, int $limit = 10): array
    {
        return self::query($table)
            ->distinct()
            ->orderBy('client_id')
            ->limit($limit)
            ->pluck('client_id')
            ->map(fn (mixed $clientId): int => (int) $clientId)
            ->all();
    }

    private static function query(string $table): Builder
    {
        return DB::table($table)
            ->whereNotNull("{$table}.client_id")
            ->whereNotExists(fn (Builder $query) => $query
                ->select(DB::raw(1))
                ->from('clients')
                ->whereColumn('clients.id', "{$table}.client_id"));
    }
}
