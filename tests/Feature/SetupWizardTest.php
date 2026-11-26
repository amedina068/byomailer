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
        $this->get('/setup?token='.$this->token())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Setup')
                ->where('token', $this->token())
                ->where('tokenIsValid', true)
                ->where('tokenWasWrong', false));
    }

    public function test_the_setup_page_without_a_token_tells_how_to_find_the_setup_url(): void
    {
        $this->get('/setup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Setup')
                ->where('token', '')
                ->where('tokenIsValid', false)
                ->where('tokenWasWrong', false)
                ->where('setupUrlCommands.0.command', 'docker logs byomailer')
                ->where('setupUrlCommands.1.command', 'docker compose logs web')
                ->where('setupUrlCommands.2.command', 'php artisan byomailer:setup-url'));
    }

    public function test_the_setup_page_with_a_wrong_token_does_not_echo_the_token(): void
    {
        $this->get('/setup?token=wrong-token')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Setup')
                ->where('token', '')
                ->where('tokenIsValid', false)
                ->where('tokenWasWrong', true));
    }

    public function test_the_setup_page_accepts_a_pasted_setup_url(): void
    {
        $pasted = urlencode('http://localhost:8080/setup?token='.$this->token());

        $this->get('/setup?token='.$pasted)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('token', $this->token())
                ->where('tokenIsValid', true));
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

    public function test_setup_sends_to_the_login_page_after_a_user_exists(): void
    {
        User::factory()->create();

        $this->get('/setup?token='.$this->token())
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'This instance is already set up. Log in with your account.');
        $this->post('/setup', $this->form())->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }

    public function test_setup_sends_to_the_login_page_when_users_exist_but_no_owner(): void
    {
        User::factory()->create(['is_owner' => false]);

        $this->get('/setup')->assertRedirect('/login');
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
