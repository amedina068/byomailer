<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tables that got client_id in 2026_02_27_000002_introduce_clients
     * without a foreign key. Later tables use foreignIdFor(Client::class).
     *
     * @var list<string>
     */
    private const TABLES = [
        'automations',
        'blacklisted_emails',
        'contact_attributes',
        'contact_imports',
        'contact_mails',
        'contacts',
        'email_credentials',
        'email_identities',
        'lists',
        'messages',
        'newsletters',
        'sequences',
        'tags',
        'user_storage_settings',
    ];

    /**
     * Add a cascading client_id foreign key to each table. MySQL creates the
     * client_id index when no index starts with client_id.
     *
     * The migration changes no rows. If a row points to a missing client, it
     * stops before the first key, and the owner decides about the cleanup.
     */
    public function up(): void
    {
        $orphans = $this->orphanCounts();

        if ($orphans !== []) {
            $tables = collect($orphans)->map(fn (int $count, string $table): string => "{$table} ({$count})")->implode(', ');

            throw new RuntimeException(
                "Cannot add the client_id foreign keys. These tables have rows whose client_id has no client: {$tables}. "
                .'Run `php artisan tenancy:orphans` to inspect them. The migration changed nothing.'
            );
        }

        $tables = array_filter(self::TABLES, fn (string $table): bool => ! Schema::hasForeignKey($table, ['client_id']));

        // The check above proved that every row has a client. With the checks
        // off, MySQL adds each key in place and does not copy the table.
        Schema::withoutForeignKeyConstraints(function () use ($tables): void {
            foreach ($tables as $table) {
                Schema::table($table, function (Blueprint $table): void {
                    $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
                });
            }
        });
    }

    /**
     * The number of rows in each table whose client_id has no client. Tables
     * with no orphans are left out. A null client_id is not an orphan.
     *
     * @return array<string, int>
     */
    private function orphanCounts(): array
    {
        return collect(self::TABLES)
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)
                ->whereNotNull("{$table}.client_id")
                ->whereNotExists(fn (Builder $query) => $query
                    ->select(DB::raw(1))
                    ->from('clients')
                    ->whereColumn('clients.id', "{$table}.client_id"))
                ->count()])
            ->filter()
            ->all();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropForeign(['client_id']);
            });
        }
    }
};
