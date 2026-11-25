<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class UserPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_the_password_from_the_prompt(): void
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);

        $this->artisan('byomailer:user:password', ['email' => 'lost@example.com'])
            ->expectsQuestion('New password', 'a-long-password-1')
            ->expectsQuestion('Type the new password again', 'a-long-password-1')
            ->expectsOutputToContain('The password of lost@example.com changed.')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('a-long-password-1', $user->fresh()->password));
    }

    public function test_it_refuses_two_different_passwords_from_the_prompt(): void
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);
        $before = $user->password;

        $this->artisan('byomailer:user:password', ['email' => 'lost@example.com'])
            ->expectsQuestion('New password', 'a-long-password-1')
            ->expectsQuestion('Type the new password again', 'a-long-password-2')
            ->expectsOutputToContain('The two passwords are not the same.')
            ->assertFailed();

        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_it_sets_the_password_from_the_option(): void
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);

        $this->artisan('byomailer:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
        ])->assertSuccessful();

        $this->assertTrue(Hash::check('a-long-password-1', $user->fresh()->password));
    }

    public function test_it_generates_a_password_that_authenticates_the_user(): void
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);

        $exitCode = Artisan::call('byomailer:user:password', [
            'email' => 'lost@example.com',
            '--generate' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, preg_match('/New password: (\S+)/', Artisan::output(), $matches));
        $this->assertGreaterThanOrEqual(20, strlen($matches[1]));
        $this->assertTrue(Hash::check($matches[1], $user->fresh()->password));
    }

    public function test_it_refuses_a_password_that_breaks_the_rules(): void
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);
        $before = $user->password;

        $this->artisan('byomailer:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'short',
        ])->expectsOutputToContain('at least 8 characters')->assertFailed();

        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_it_refuses_an_unknown_email(): void
    {
        $this->artisan('byomailer:user:password', [
            'email' => 'nobody@example.com',
            '--password' => 'a-long-password-1',
        ])->expectsOutputToContain('No user has the email nobody@example.com.')->assertFailed();
    }

    public function test_it_refuses_the_password_and_the_generate_options_together(): void
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);
        $before = $user->password;

        $this->artisan('byomailer:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
            '--generate' => true,
        ])->assertFailed();

        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_it_rotates_the_remember_token(): void
    {
        $user = User::factory()->create(['email' => 'lost@example.com', 'remember_token' => 'old-token']);

        $this->artisan('byomailer:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
        ])->expectsOutputToContain('"Remember me" logins ended')->assertSuccessful();

        $this->assertNotSame('old-token', $user->fresh()->remember_token);
    }

    public function test_it_ends_the_database_sessions_of_the_user(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create(['email' => 'lost@example.com']);
        $other = User::factory()->create();

        DB::table('sessions')->insert([
            ['id' => 'a', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'b', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'c', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->artisan('byomailer:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
        ])->expectsOutputToContain('Ended 2 web sessions')->assertSuccessful();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    public function test_it_explains_the_session_effect_for_other_drivers(): void
    {
        config(['session.driver' => 'file']);
        User::factory()->create(['email' => 'lost@example.com']);

        $this->artisan('byomailer:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
        ])->expectsOutputToContain('The "file" session driver')->assertSuccessful();
    }

    public function test_it_resets_two_factor_authentication(): void
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);
        $user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt('[]'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->artisan('byomailer:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
            '--reset-two-factor' => true,
        ])->expectsOutputToContain('Two-factor authentication is off')->assertSuccessful();

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_it_keeps_two_factor_authentication_without_the_option(): void
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);
        $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

        $this->artisan('byomailer:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
        ])->assertSuccessful();

        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    public function test_it_does_not_reactivate_a_deactivated_user(): void
    {
        $user = User::factory()->create(['email' => 'lost@example.com', 'deactivated_at' => now()]);

        $this->artisan('byomailer:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
        ])->expectsOutputToContain('This user is deactivated')->assertSuccessful();

        $this->assertTrue($user->fresh()->isDeactivated());
    }

    public function test_a_generated_password_passes_the_production_rules(): void
    {
        Password::defaults(fn (): Password => Password::min(12)->mixedCase()->letters()->numbers()->symbols());
        $user = User::factory()->create(['email' => 'lost@example.com']);

        $exitCode = Artisan::call('byomailer:user:password', [
            'email' => 'lost@example.com',
            '--generate' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, preg_match('/New password: (\S+)/', Artisan::output(), $matches));
        $this->assertTrue(Hash::check($matches[1], $user->fresh()->password));
    }

    public function test_an_open_web_session_ends_at_its_next_request(): void
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);

        $this->post('/login', ['email' => 'lost@example.com', 'password' => 'password']);
        $this->app['auth']->forgetGuards();
        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user);

        $this->artisan('byomailer:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
        ])->assertSuccessful();

        // The next request reads the user from the session, the same as a new HTTP request.
        $this->app['auth']->forgetGuards();

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }
}
