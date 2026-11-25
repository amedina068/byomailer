<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The owner migration keeps the access of the current operators. The users in
 * ADMIN_EMAILS become owners. The team admins of the clients do not.
 */
class OwnerMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_17_000001_add_is_owner_to_users_table.php');
    }

    public function test_the_users_in_admin_emails_become_owners(): void
    {
        $operator = User::factory()->create(['email' => 'Ops@Example.com']);
        $teamAdmin = User::factory()->create(['email' => 'customer@example.com']);

        config(['app.admin_emails' => ['ops@example.com']]);

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $this->assertTrue($operator->fresh()->isOwner());
        $this->assertFalse($teamAdmin->fresh()->isOwner());
    }

    public function test_no_user_becomes_owner_when_admin_emails_is_empty(): void
    {
        $user = User::factory()->create();

        config(['app.admin_emails' => []]);

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $this->assertFalse($user->fresh()->isOwner());
    }

    public function test_a_user_in_admin_emails_without_a_verified_email_does_not_become_owner(): void
    {
        $verified = User::factory()->create(['email' => 'ops@example.com']);
        $unverified = User::factory()->unverified()->create(['email' => 'squatter@example.com']);

        config(['app.admin_emails' => ['ops@example.com', 'squatter@example.com']]);

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $this->assertTrue($verified->fresh()->isOwner());
        $this->assertFalse($unverified->fresh()->isOwner());
    }
}
