<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Newsletter;
use App\Models\User;
use App\Support\TenantOrphans;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClassConstant;
use RuntimeException;
use Tests\TestCase;

/**
 * Each client_id column has a foreign key to clients. The database refuses a
 * row with an unknown tenant, and a deleted client takes its rows with it.
 */
class ClientForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_18_000001_add_client_foreign_keys.php');
    }

    /**
     * SQLite checks deferred keys at commit. RefreshDatabase rolls the test
     * back, so the orphan row that this makes is never checked.
     */
    private function orphan(string $table, int $id): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table($table)->where('id', $id)->update(['client_id' => 999999]);
    }

    public function test_every_client_id_column_has_a_cascading_foreign_key_to_clients(): void
    {
        $tables = TenantOrphans::tables();

        $this->assertContains('contacts', $tables);
        $this->assertContains('contact_mails', $tables);

        foreach ($tables as $table) {
            $foreignKey = collect(Schema::getForeignKeys($table))
                ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['client_id']);

            $this->assertNotNull($foreignKey, "{$table}.client_id has no foreign key.");
            $this->assertSame('clients', $foreignKey['foreign_table'], "{$table}.client_id does not reference clients.");
            $this->assertSame(['id'], $foreignKey['foreign_columns']);
            $this->assertSame('cascade', strtolower($foreignKey['on_delete']), "{$table}.client_id does not cascade on delete.");
        }
    }

    public function test_the_database_refuses_a_row_with_an_unknown_client_id(): void
    {
        $newsletter = Newsletter::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');

        DB::table('newsletters')->where('id', $newsletter->id)->update(['client_id' => 999999]);
    }

    public function test_deleting_a_client_leaves_no_orphan_rows(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        DB::table('blacklisted_emails')->insert([
            'client_id' => $user->client_id,
            'user_id' => null,
            'email' => 'blocked@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('clients')->where('id', $user->client_id)->delete();

        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
        $this->assertDatabaseMissing('blacklisted_emails', ['email' => 'blocked@example.com']);
        $this->assertSame([], TenantOrphans::counts(TenantOrphans::tables()));
    }

    public function test_the_migration_refuses_to_add_the_keys_while_a_table_has_orphan_rows(): void
    {
        $contacts = Contact::factory()->count(2)->create();
        $newsletter = Newsletter::factory()->create();
        $this->orphan('contacts', $contacts[0]->id);
        $this->orphan('contacts', $contacts[1]->id);
        $this->orphan('newsletters', $newsletter->id);

        try {
            $this->migration()->up();
            $this->fail('The migration ran with orphan rows.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('contacts (2)', $exception->getMessage());
            $this->assertStringContainsString('newsletters (1)', $exception->getMessage());
            $this->assertStringContainsString('php artisan tenancy:orphans', $exception->getMessage());
        }

        $this->assertDatabaseCount('contacts', 2);
        $this->assertDatabaseHas('newsletters', ['id' => $newsletter->id, 'client_id' => 999999]);
    }

    public function test_the_migration_and_tenant_orphans_cover_the_same_tables_and_count_the_same_rows(): void
    {
        $migration = $this->migration();
        $migrationTables = (new ReflectionClassConstant($migration, 'TABLES'))->getValue();

        $this->assertSame([], array_diff($migrationTables, TenantOrphans::tables()));

        $contacts = Contact::factory()->count(2)->create();
        $newsletter = Newsletter::factory()->create();
        $this->orphan('contacts', $contacts[0]->id);
        $this->orphan('contacts', $contacts[1]->id);
        $this->orphan('newsletters', $newsletter->id);
        DB::table('blacklisted_emails')->insert([
            ['client_id' => 999999, 'email' => 'orphan@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['client_id' => null, 'email' => 'global@example.com', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $expected = collect(TenantOrphans::counts($migrationTables))
            ->map(fn (int $count, string $table): string => "{$table} ({$count})")
            ->implode(', ');

        $this->assertSame('blacklisted_emails (1), contacts (2), newsletters (1)', $expected);
        $this->expectExceptionMessage("These tables have rows whose client_id has no client: {$expected}.");

        $migration->up();
    }

    public function test_the_migration_does_not_count_a_global_blacklist_row_as_an_orphan(): void
    {
        DB::table('blacklisted_emails')->insert([
            'client_id' => null,
            'user_id' => null,
            'email' => 'global@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertSame([], TenantOrphans::counts(['blacklisted_emails']));
    }
}
