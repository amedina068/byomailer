<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Newsletter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `tenancy:orphans` reports the rows whose client_id has no client. It only
 * reads. The owner decides about a cleanup.
 */
class TenancyOrphansCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SQLite checks deferred keys at commit. RefreshDatabase rolls the test
     * back, so the orphan row that this makes is never checked.
     */
    private function orphan(string $table, int $id, int $clientId): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table($table)->where('id', $id)->update(['client_id' => $clientId]);
    }

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
        $this->orphan('contacts', $contacts[0]->id, 900001);
        $this->orphan('contacts', $contacts[1]->id, 900002);
        $this->orphan('newsletters', $newsletter->id, 900001);

        $this->artisan('tenancy:orphans')
            ->expectsTable(['Table', 'Orphan rows', 'Missing client ids'], [
                ['contacts', 2, '900001, 900002'],
                ['newsletters', 1, '900001'],
            ])
            ->assertFailed();

        $this->assertDatabaseCount('contacts', 3);
        $this->assertDatabaseHas('contacts', ['id' => $contacts[0]->id, 'client_id' => 900001]);
    }
}
