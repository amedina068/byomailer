<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Newsletter;
use App\Support\TenantOrphans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesOrphanRows;
use Tests\TestCase;

/**
 * `tenancy:orphans` reports the rows whose client_id has no client. It only
 * reads. The owner decides about a cleanup.
 */
class TenancyOrphansCommandTest extends TestCase
{
    use MakesOrphanRows;
    use RefreshDatabase;

    public function test_it_reports_no_orphans_when_every_row_has_a_client(): void
    {
        Contact::factory()->create();

        $this->artisan('tenancy:orphans')
            ->expectsOutputToContain('No orphan rows.')
            ->assertSuccessful();
    }

    public function test_it_lists_each_table_with_its_orphan_count_and_missing_client_ids(): void
    {
        $contacts = Contact::factory()->count(3)->create();
        $newsletter = Newsletter::factory()->create();
        $this->makeOrphan('contacts', $contacts[0]->id, 900001);
        $this->makeOrphan('contacts', $contacts[1]->id, 900002);
        $this->makeOrphan('newsletters', $newsletter->id, 900001);

        $this->artisan('tenancy:orphans')
            ->expectsTable(['Table', 'Orphan rows', 'Missing client ids'], [
                ['contacts', 2, '900001, 900002'],
                ['newsletters', 1, '900001'],
            ])
            ->assertFailed();

        $this->assertDatabaseCount('contacts', 3);
        $this->assertDatabaseHas('contacts', ['id' => $contacts[0]->id, 'client_id' => 900001]);
    }

    /**
     * MySQL lists the tables of each database that the user can read. The
     * command must check the tables of the app database only, and each table
     * one time. SQLite lists only the app database, because the app attaches
     * no other database. So the test runs on MySQL only.
     */
    public function test_it_checks_only_the_tables_of_the_app_database(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Only MySQL lists the tables of other databases on the same server.');
        }

        // DDL on a second connection does not commit the transaction of the test.
        $other = DB::getDatabaseName().'_other';
        config(['database.connections.other_database' => DB::connection()->getConfig()]);
        $connection = DB::connection('other_database');

        try {
            $connection->statement("CREATE DATABASE IF NOT EXISTS `{$other}`");
            $connection->statement("CREATE TABLE `{$other}`.`contacts` (`id` bigint unsigned, `client_id` bigint unsigned)");

            $tables = TenantOrphans::tables();

            $this->assertContains('contacts', $tables);
            $this->assertSame(array_values(array_unique($tables)), $tables);
        } finally {
            $connection->statement("DROP DATABASE IF EXISTS `{$other}`");
            DB::purge('other_database');
        }
    }
}
