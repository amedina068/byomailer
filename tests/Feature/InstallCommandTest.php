<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_first_admin_user_from_options(): void
    {
        $this->artisan('byomailer:install', [
            '--name' => 'Ada Admin',
            '--email' => 'ada@example.com',
            '--password' => 'a-long-secret-password',
            '--timezone' => 'Europe/Madrid',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        $this->assertSame('Ada Admin', $user->name);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue($user->isOwner());
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('a-long-secret-password', $user->password));
        $this->assertNotNull($user->client_id);
        $this->assertSame('Europe/Madrid', $user->client->timezone);
        $this->assertTrue(EmailTemplate::query()->withoutGlobalScopes()->where('client_id', $user->client_id)->exists());
    }

    public function test_it_asks_for_missing_values(): void
    {
        $this->artisan('byomailer:install')
            ->expectsQuestion('Name', 'Prompted Admin')
            ->expectsQuestion('Email', 'prompted@example.com')
            ->expectsOutputToContain('/login/link/')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'prompted@example.com', 'name' => 'Prompted Admin']);
    }

    public function test_it_refuses_to_run_when_a_user_already_exists(): void
    {
        User::factory()->create();

        $this->artisan('byomailer:install', [
            '--name' => 'Second Admin',
            '--email' => 'second@example.com',
            '--password' => 'a-long-secret-password',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('byomailer:install', [
            '--name' => 'Bad Email',
            '--email' => 'not-an-email',
            '--password' => 'a-long-secret-password',
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_rejects_a_short_password(): void
    {
        $this->artisan('byomailer:install', [
            '--name' => 'Short Password',
            '--email' => 'short@example.com',
            '--password' => 'short',
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_works_when_registration_is_disabled(): void
    {
        config(['app.registration_enabled' => false]);

        $this->artisan('byomailer:install', [
            '--name' => 'Closed Admin',
            '--email' => 'closed@example.com',
            '--password' => 'a-long-secret-password',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'closed@example.com']);
    }

    public function test_prepare_creates_no_account(): void
    {
        $this->artisan('byomailer:install', ['--prepare' => true])->assertSuccessful();

        $this->assertSame(0, User::query()->count());
    }

    public function test_prepare_succeeds_when_users_exist(): void
    {
        User::factory()->create();

        $this->artisan('byomailer:install', ['--prepare' => true])->assertSuccessful();

        $this->assertSame(1, User::query()->count());
    }

    public function test_promote_makes_an_existing_user_the_owner(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.com', 'is_owner' => false]);

        $this->artisan('byomailer:install', ['--promote' => 'ops@example.com'])->assertSuccessful();

        $this->assertTrue($user->fresh()->isOwner());
        $this->assertSame(1, User::query()->count());
    }

    public function test_promote_fails_for_an_unknown_email(): void
    {
        $this->artisan('byomailer:install', ['--promote' => 'nobody@example.com'])->assertFailed();
    }
}
