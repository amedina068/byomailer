<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Registration;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private const STATE = 'state-from-the-session';

    private const VERIFIER = 'pkce-verifier-from-the-session-0123456789-abcdefghijklmnopqrstu';

    /** @var list<array{request: RequestInterface}> */
    private array $googleRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client-id.apps.googleusercontent.com',
            'services.google.client_secret' => 'test-client-secret',
        ]);
    }

    public function test_the_routes_return_404_when_google_sign_in_is_not_configured(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->get(route('google.redirect'))->assertNotFound();
        $this->get(route('google.callback'))->assertNotFound();
    }

    public function test_the_routes_return_404_when_the_client_secret_is_missing(): void
    {
        config(['services.google.client_secret' => '']);

        $this->get(route('google.redirect'))->assertNotFound();
        $this->get(route('google.callback'))->assertNotFound();
    }

    public function test_the_login_page_offers_google_only_when_it_is_configured(): void
    {
        $this->completeInstanceSetup();

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->where('canUseGoogle', true));

        config(['services.google.client_id' => null]);

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->where('canUseGoogle', false));
    }

    public function test_the_login_page_shows_the_reason_of_a_refused_google_sign_in(): void
    {
        $this->completeInstanceSetup();

        $this->withSession(['google_error' => 'The Google sign-in did not complete. Try again.'])
            ->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('googleError', 'The Google sign-in did not complete. Try again.'));
    }

    public function test_the_redirect_sends_the_user_to_google_with_a_state_and_a_pkce_challenge(): void
    {
        $response = $this->get(route('google.redirect'));

        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://accounts.google.com/', $location);
        $this->assertSame(session('state'), $query['state']);
        $this->assertSame(40, strlen($query['state']));
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertSame(url('/auth/google/callback'), $query['redirect_uri']);
    }

    public function test_a_linked_user_logs_in(): void
    {
        $user = User::factory()->create(['google_id' => '1001']);
        $this->fakeGoogle(['sub' => '1001', 'email' => $user->email, 'email_verified' => true]);

        $this->returnFromGoogle()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_token_request_sends_the_code_and_the_pkce_verifier(): void
    {
        $user = User::factory()->create(['google_id' => '1001']);
        $this->fakeGoogle(['sub' => '1001', 'email' => $user->email, 'email_verified' => true]);

        $this->returnFromGoogle();

        parse_str((string) $this->googleRequests[0]['request']->getBody(), $fields);

        $this->assertSame('fake-code', $fields['code']);
        $this->assertSame(self::VERIFIER, $fields['code_verifier']);
    }

    public function test_a_verified_google_email_links_an_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $this->fakeGoogle(['sub' => '2002', 'email' => 'jane@example.com', 'email_verified' => true]);

        $this->returnFromGoogle()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('2002', $user->fresh()->google_id);
    }

    public function test_the_email_match_ignores_the_case(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $this->fakeGoogle(['sub' => '2002', 'email' => 'Jane@Example.COM', 'email_verified' => true]);

        $this->returnFromGoogle()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('jane@example.com', $user->fresh()->email);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unverifiedClaims(): array
    {
        return [
            'false' => [['email_verified' => false]],
            'missing' => [[]],
            'a string' => [['email_verified' => 'true']],
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    #[DataProvider('unverifiedClaims')]
    public function test_an_unverified_google_email_does_not_link(array $claims): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $this->fakeGoogle(['sub' => '2002', 'email' => 'jane@example.com', ...$claims]);

        $this->assertRefused($this->returnFromGoogle(), 'Google did not verify this email address. Log in with your password.');

        $this->assertNull($user->fresh()->google_id);
    }

    public function test_a_stranger_gets_no_account(): void
    {
        $this->completeInstanceSetup();
        $this->fakeGoogle(['sub' => '3003', 'email' => 'stranger@example.com', 'email_verified' => true]);

        $this->assertRefused($this->returnFromGoogle(), 'No account uses this email address. Ask a team admin for an invitation.');

        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
    }

    public function test_a_stranger_gets_no_account_when_registration_is_open(): void
    {
        $this->completeInstanceSetup();
        Registration::setMode(Registration::OPEN);
        $this->fakeGoogle(['sub' => '3003', 'email' => 'stranger@example.com', 'email_verified' => true]);

        $this->assertRefused($this->returnFromGoogle(), 'No account uses this email address. Ask a team admin for an invitation.');

        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
    }

    public function test_a_lookalike_email_does_not_link(): void
    {
        $user = User::factory()->create(['email' => 'kate@example.com']);
        $this->fakeGoogle(['sub' => '4004', 'email' => "\u{212A}ate@example.com", 'email_verified' => true]);

        $this->assertRefused($this->returnFromGoogle(), 'No account uses this email address. Ask a team admin for an invitation.');

        $this->assertNull($user->fresh()->google_id);
    }

    public function test_a_user_with_a_pending_invitation_cannot_use_google(): void
    {
        $invitee = User::factory()->create([
            'email' => 'invitee@example.com',
            'role' => UserRole::Editor->value,
            'invite_expires_at' => now()->addDay(),
            'email_verified_at' => null,
        ]);
        $this->fakeGoogle(['sub' => '5005', 'email' => 'invitee@example.com', 'email_verified' => true]);

        $this->assertRefused($this->returnFromGoogle(), 'Accept your invitation first. Open the link in the invitation email.');

        $this->assertNull($invitee->fresh()->google_id);
    }

    public function test_an_account_with_an_unconfirmed_email_does_not_link(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com', 'email_verified_at' => null]);
        $this->fakeGoogle(['sub' => '2002', 'email' => 'jane@example.com', 'email_verified' => true]);

        $this->assertRefused($this->returnFromGoogle(), 'This account has no confirmed email address. Log in with your password.');

        $this->assertNull($user->fresh()->google_id);
    }

    public function test_a_deactivated_user_cannot_use_google(): void
    {
        $user = User::factory()->create(['google_id' => '1001', 'deactivated_at' => now()]);
        $this->fakeGoogle(['sub' => '1001', 'email' => $user->email, 'email_verified' => true]);

        $this->assertRefused($this->returnFromGoogle(), User::DEACTIVATED_MESSAGE);
    }

    public function test_an_archived_user_cannot_use_google(): void
    {
        $user = User::factory()->create(['google_id' => '1001', 'archived_at' => now()]);
        $this->fakeGoogle(['sub' => '1001', 'email' => $user->email, 'email_verified' => true]);

        $this->assertRefused($this->returnFromGoogle(), 'This account is archived.');
    }

    public function test_an_account_linked_to_another_google_account_is_not_linked_again(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com', 'google_id' => '1001']);
        $this->fakeGoogle(['sub' => '9009', 'email' => 'jane@example.com', 'email_verified' => true]);

        $this->assertRefused($this->returnFromGoogle(), 'This account is linked to a different Google account.');

        $this->assertSame('1001', $user->fresh()->google_id);
    }

    public function test_a_user_with_two_factor_authentication_must_pass_the_challenge(): void
    {
        $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
        $user = User::factory()->create(['google_id' => '1001']);
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $this->fakeGoogle(['sub' => '1001', 'email' => $user->email, 'email_verified' => true]);

        $this->returnFromGoogle()
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $user->id);
        $this->assertGuest();

        $this->post(route('two-factor.login.store'), ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_forged_state_is_refused_before_any_call_to_google(): void
    {
        User::factory()->create(['google_id' => '1001']);
        $this->fakeGoogleResponses([]);

        $this->assertRefused($this->returnFromGoogle('forged-state'), 'The Google sign-in did not complete. Try again.');

        $this->assertSame([], $this->googleRequests);
    }

    public function test_a_callback_without_a_state_in_the_session_is_refused(): void
    {
        User::factory()->create(['google_id' => '1001']);
        $this->fakeGoogleResponses([]);

        $response = $this->get(route('google.callback', ['state' => self::STATE, 'code' => 'fake-code']));

        $this->assertRefused($response, 'The Google sign-in did not complete. Try again.');
        $this->assertSame([], $this->googleRequests);
    }

    public function test_a_canceled_consent_is_refused(): void
    {
        $this->fakeGoogleResponses([new Response(400, [], '{"error":"invalid_request"}')]);

        $response = $this->withSession(['state' => self::STATE, 'code_verifier' => self::VERIFIER])
            ->get(route('google.callback', ['state' => self::STATE, 'error' => 'access_denied']));

        $this->assertRefused($response, 'The Google sign-in did not complete. Try again.');
    }

    public function test_a_logged_in_user_does_not_start_a_new_sign_in(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('google.redirect'))
            ->assertRedirect(route('dashboard'));
    }

    private function returnFromGoogle(string $state = self::STATE): TestResponse
    {
        return $this->withSession(['state' => self::STATE, 'code_verifier' => self::VERIFIER])
            ->get(route('google.callback', ['state' => $state, 'code' => 'fake-code']));
    }

    private function assertRefused(TestResponse $response, string $message): void
    {
        $response->assertRedirect(route('login'))->assertSessionHas('google_error', $message);

        $this->assertGuest();
    }

    /**
     * Answer the token request and the user info request of Google.
     *
     * @param  array<string, mixed>  $claims
     */
    private function fakeGoogle(array $claims): void
    {
        $this->fakeGoogleResponses([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'access_token' => 'fake-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3599,
                'scope' => 'openid email profile',
            ])),
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'name' => 'Test User',
                'picture' => 'https://example.com/avatar.png',
                ...$claims,
            ])),
        ]);
    }

    /**
     * Use the real Google provider of Socialite, so that the state and PKCE
     * checks run. Only the HTTP client is fake. An unexpected request fails
     * the test, because the mock handler has no response for it.
     *
     * @param  list<Response>  $responses
     */
    private function fakeGoogleResponses(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->googleRequests));

        Socialite::extend('google', fn () => Socialite::buildProvider(GoogleProvider::class, config('services.google'))
            ->setHttpClient(new Client(['handler' => $stack])));
    }
}
