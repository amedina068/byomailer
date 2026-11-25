<?php

namespace Tests\Feature;

use App\Auth\ActiveUserProvider;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * A deactivated user keeps every row, and loses every way in. Each test below
 * covers one entry point that authenticates as a user.
 */
class DeactivatedUserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Drop the guards that the earlier request built, so that the next request
     * authenticates from the start, the same as a new HTTP request.
     */
    private function freshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    // The model

    public function test_deactivate_and_reactivate_change_the_timestamp(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->isDeactivated());

        $user->deactivate();
        $this->assertTrue($user->fresh()->isDeactivated());

        $user->reactivate();
        $this->assertFalse($user->fresh()->isDeactivated());
    }

    public function test_the_user_provider_does_not_return_a_deactivated_user(): void
    {
        $user = User::factory()->create(['deactivated_at' => now(), 'remember_token' => 'remember-me']);

        $provider = Auth::createUserProvider('users');

        $this->assertInstanceOf(ActiveUserProvider::class, $provider);
        $this->assertNull($provider->retrieveById($user->id));
        $this->assertNull($provider->retrieveByToken($user->id, 'remember-me'));

        $user->reactivate();
        $this->assertSame($user->id, $provider->retrieveById($user->id)?->id);
        $this->assertSame($user->id, $provider->retrieveByToken($user->id, 'remember-me')?->id);
    }

    // The login form

    public function test_a_deactivated_user_cannot_log_in_and_reads_why(): void
    {
        $user = User::factory()->create(['deactivated_at' => now()]);

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => User::DEACTIVATED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_wrong_password_does_not_tell_that_the_account_is_deactivated(): void
    {
        $user = User::factory()->create(['deactivated_at' => now()]);

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertNotSame(User::DEACTIVATED_MESSAGE, session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_a_deactivated_user_with_two_factor_gets_no_challenge(): void
    {
        if (! Features::canManageTwoFactorAuthentication()) {
            $this->markTestSkipped('Two-factor authentication is not enabled.');
        }

        $user = User::factory()->withTwoFactor()->create(['deactivated_at' => now()]);

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => User::DEACTIVATED_MESSAGE])
            ->assertSessionMissing('login.id');
    }

    public function test_a_reactivated_user_can_log_in_again(): void
    {
        $user = User::factory()->create(['deactivated_at' => now()]);
        $user->reactivate();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    // An open web session and the remember-me cookie

    public function test_an_open_session_ends_at_the_next_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->deactivate();

        $this->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => User::DEACTIVATED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_stored_session_of_a_deactivated_user_ends_with_the_message(): void
    {
        $user = User::factory()->create(['deactivated_at' => now()]);
        $sessionKey = Auth::guard('web')->getName();

        $this->withSession([$sessionKey => $user->id])
            ->get('/contacts')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => User::DEACTIVATED_MESSAGE])
            ->assertSessionMissing($sessionKey);

        $this->assertGuest();
    }

    public function test_a_remember_me_cookie_of_a_deactivated_user_does_not_log_in(): void
    {
        $user = User::factory()->create(['deactivated_at' => now(), 'remember_token' => 'remember-me']);
        $guard = Auth::guard('web');

        $this->withCookie($guard->getRecallerName(), $user->id.'|remember-me|'.$user->password)
            ->get('/dashboard')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_a_json_request_from_a_deactivated_session_gets_a_401(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->deactivate();

        $this->getJson('/email-templates/list')
            ->assertUnauthorized()
            ->assertJson(['message' => User::DEACTIVATED_MESSAGE]);
    }

    public function test_a_session_of_an_active_user_is_not_touched(): void
    {
        $user = User::factory()->create();
        $sessionKey = Auth::guard('web')->getName();

        $this->withSession([$sessionKey => $user->id])->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    // Sanctum API tokens

    public function test_an_api_token_stops_working_and_works_again_after_reactivation(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('cli')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/tags')->assertOk();

        $user->deactivate();
        $this->freshRequest();
        $this->withToken($token)->getJson('/api/v1/tags')->assertUnauthorized();
        $this->freshRequest();
        $this->withToken($token)->postJson('/api/v1/tags', ['name' => 'Blocked'])->assertUnauthorized();
        $this->assertDatabaseMissing('tags', ['name' => 'Blocked']);

        $user->reactivate();
        $this->freshRequest();
        $this->withToken($token)->getJson('/api/v1/tags')->assertOk();
    }

    // Signed invitation links

    public function test_the_invitation_link_of_a_deactivated_user_does_not_log_in(): void
    {
        $invited = User::factory()->create([
            'role' => UserRole::Viewer,
            'invite_expires_at' => now()->addDay(),
            'email_verified_at' => null,
            'deactivated_at' => now(),
        ]);

        $signedUrl = URL::temporarySignedRoute('invites.show', now()->addDay(), ['user' => $invited->uuid]);

        $this->get($signedUrl)->assertStatus(410);

        $this->post(route('invites.store', ['user' => $invited->uuid]).'?'.parse_url($signedUrl, PHP_URL_QUERY), [
            'email' => $invited->email,
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertStatus(410);

        $this->assertGuest();
        $this->assertNotNull($invited->fresh()->invite_expires_at);
    }
}
