<?php

namespace Tests\Feature;

use App\Actions\Instance\CreateInstanceOwner;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        return substr(hash_hmac('sha256', 'byomailer-setup', (string) config('app.key')), 0, 32);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'token' => $this->token(),
            'name' => 'Olive Owner',
            'email' => 'owner@example.com',
            'password' => 'a-long-password-1',
            'password_confirmation' => 'a-long-password-1',
            'timezone' => 'Europe/Madrid',
        ], $overrides);
    }

    public function test_the_setup_page_shows_the_form_with_the_token_from_the_url(): void
    {
        $this->get('/setup?token=abc123')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Setup')
                ->where('token', 'abc123')
                ->where('setupUrlCommand', 'php artisan byomailer:setup-url'));
    }

    public function test_a_wrong_token_creates_no_account(): void
    {
        $this->post('/setup', $this->form(['token' => 'wrong-token']))
            ->assertSessionHasErrors('token');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_a_missing_token_creates_no_account(): void
    {
        $this->post('/setup', $this->form(['token' => '']))
            ->assertSessionHasErrors('token');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_right_token_creates_the_owner_and_signs_in(): void
    {
        $this->post('/setup', $this->form())
            ->assertRedirect(route('dashboard', absolute: false));

        $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();

        $this->assertTrue($owner->isOwner());
        $this->assertSame(UserRole::Admin, $owner->role);
        $this->assertSame('Olive Owner', $owner->name);
        $this->assertSame('Europe/Madrid', $owner->client->timezone);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue(Hash::check('a-long-password-1', $owner->password));
        $this->assertAuthenticatedAs($owner);
    }

    public function test_the_password_confirmation_must_match(): void
    {
        $this->post('/setup', $this->form(['password_confirmation' => 'something-else-1']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_invalid_email_creates_no_account(): void
    {
        $this->post('/setup', $this->form(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_is_not_found_after_a_user_exists(): void
    {
        User::factory()->create();

        $this->get('/setup?token='.$this->token())->assertNotFound();
        $this->post('/setup', $this->form())->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }

    public function test_setup_is_not_found_when_users_exist_but_no_owner(): void
    {
        User::factory()->create(['is_owner' => false]);

        $this->get('/setup')->assertNotFound();
    }

    public function test_the_entry_pages_send_a_new_instance_to_setup(): void
    {
        $this->get('/')->assertRedirect('/setup');
        $this->get('/login')->assertRedirect('/setup');
        $this->get('/register')->assertRedirect('/setup');
    }

    public function test_open_registration_cannot_create_the_first_account_before_setup(): void
    {
        config(['app.registration_enabled' => true]);

        $this->post('/register', [
            'name' => 'Stranger',
            'email' => 'stranger@example.com',
            'timezone' => 'UTC',
            'password' => 'a-long-password-1',
            'password_confirmation' => 'a-long-password-1',
        ])->assertRedirect('/setup');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_the_entry_pages_do_not_redirect_after_setup(): void
    {
        $this->post('/setup', $this->form());
        auth()->logout();

        $this->get('/')->assertRedirect(route('login'));
        $this->get('/login')->assertOk();
    }

    public function test_other_pages_do_not_redirect_while_setup_is_needed(): void
    {
        $this->get('/documentation/guides/getting-started')->assertOk();
        $this->get('/forgot-password')->assertOk();
    }

    public function test_the_owner_action_creates_no_first_owner_when_a_user_appeared_meanwhile(): void
    {
        User::factory()->create(['email' => 'first@example.com']);

        $owner = app(CreateInstanceOwner::class)->createFirstOwner([
            'name' => 'Late Owner',
            'email' => 'late@example.com',
            'password' => 'a-long-password-1',
        ]);

        $this->assertNull($owner);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('users', ['email' => 'late@example.com']);
    }
}
