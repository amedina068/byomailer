<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'timezone' => 'America/Chicago',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertSame('America/Chicago', $user->client->timezone);
        $this->assertNull($user->email_verified_at);
    }

    public function test_sunset_setting_rejects_values_outside_the_range(): void
    {
        $user = User::factory()->create();

        foreach ([0, 731] as $days) {
            $this->actingAs($user)
                ->patch(route('profile.update'), $this->profilePayload($user, ['sunset_inactive_days' => $days]))
                ->assertSessionHasErrors('sunset_inactive_days');
        }

        $this->assertNull($user->client->fresh()->sunset_inactive_days);
    }

    public function test_sunset_setting_is_saved_on_the_client(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->profilePayload($user, ['sunset_inactive_days' => '90']))
            ->assertSessionHasNoErrors();

        $this->assertSame(90, $user->client->fresh()->sunset_inactive_days);
    }

    public function test_sunset_setting_off_clears_the_client_value(): void
    {
        $user = User::factory()->create();
        $user->client->forceFill(['sunset_inactive_days' => 90])->save();

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->profilePayload($user, ['sunset_inactive_days' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($user->client->fresh()->sunset_inactive_days);
    }

    public function test_profile_update_without_the_sunset_field_keeps_the_client_value(): void
    {
        $user = User::factory()->create();
        $user->client->forceFill(['sunset_inactive_days' => 90])->save();

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->profilePayload($user))
            ->assertSessionHasNoErrors();

        $this->assertSame(90, $user->client->fresh()->sunset_inactive_days);
    }

    public function test_profile_page_shows_the_sunset_setting(): void
    {
        $user = User::factory()->create();
        $user->client->forceFill(['sunset_inactive_days' => 180])->save();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sunsetInactiveDays', 180)
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('sunsetPreview', ['count' => 0, 'days' => 180])
                )
            );
    }

    public function test_sunset_preview_is_null_when_the_setting_is_off(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sunsetInactiveDays', null)
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('sunsetPreview', null))
            );
    }

    public function test_a_viewer_cannot_change_the_account_settings_of_the_client(): void
    {
        $viewer = $this->viewer();
        $viewer->client->forceFill([
            'timezone' => 'UTC',
            'sunset_inactive_days' => null,
            'postal_address' => '1 Main St',
        ])->save();

        $this->actingAs($viewer)
            ->patch(route('profile.update'), [
                'name' => $viewer->name,
                'email' => $viewer->email,
                'timezone' => 'America/Chicago',
                'sunset_inactive_days' => '30',
                'postal_address' => 'Somewhere else',
            ])
            ->assertSessionHasErrors(['timezone', 'sunset_inactive_days', 'postal_address']);

        $client = $viewer->client->fresh();
        $this->assertSame('UTC', $client->timezone);
        $this->assertNull($client->sunset_inactive_days);
        $this->assertSame('1 Main St', $client->postal_address);
    }

    public function test_a_viewer_can_update_the_own_profile_when_the_client_settings_do_not_change(): void
    {
        $viewer = $this->viewer();
        $viewer->client->forceFill([
            'timezone' => 'Europe/Madrid',
            'sunset_inactive_days' => 90,
            'postal_address' => null,
        ])->save();

        $this->actingAs($viewer)
            ->patch(route('profile.update'), [
                'name' => 'New Viewer Name',
                'email' => $viewer->email,
                'timezone' => 'Europe/Madrid',
                'sunset_inactive_days' => '90',
                'postal_address' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('New Viewer Name', $viewer->fresh()->name);
        $this->assertSame(90, $viewer->client->fresh()->sunset_inactive_days);
    }

    public function test_an_editor_can_change_the_account_settings_of_the_client(): void
    {
        $admin = User::factory()->create();
        $editor = User::factory()->teammateOf($admin)->create(['role' => UserRole::Editor->value]);

        $this->actingAs($editor)
            ->patch(route('profile.update'), $this->profilePayload($editor, [
                'timezone' => 'America/Chicago',
                'sunset_inactive_days' => '120',
            ]))
            ->assertSessionHasNoErrors();

        $client = $admin->client->fresh();
        $this->assertSame('America/Chicago', $client->timezone);
        $this->assertSame(120, $client->sunset_inactive_days);
    }

    private function viewer(): User
    {
        $admin = User::factory()->create();

        return User::factory()->teammateOf($admin)->create(['role' => UserRole::Viewer->value]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function profilePayload(User $user, array $overrides = []): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => 'UTC',
            ...$overrides,
        ];
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
                'timezone' => $user->client->timezone,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_timezone_must_be_a_valid_identifier(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
                'timezone' => 'Mars/Olympus-Mons',
            ]);

        $response
            ->assertSessionHasErrors('timezone')
            ->assertRedirect(route('profile.edit'));

        $this->assertSame($user->client->timezone, $user->fresh()->client->timezone);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
