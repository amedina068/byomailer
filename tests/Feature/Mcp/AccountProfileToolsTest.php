<?php

namespace Tests\Feature\Mcp;

use App\Enums\UserRole;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\UpdateAccountProfileTool;
use App\Mcp\Tools\ViewAccountProfileTool;
use App\Models\Client;
use App\Models\User;
use App\Services\SendableContentCheck;
use App\Services\SunsetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AccountProfileToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_account_profile_returns_the_settings_of_the_own_client(): void
    {
        $user = User::factory()->create(['name' => 'Ada Admin']);
        $user->client->update([
            'timezone' => 'America/Chicago',
            'postal_address' => '1 Main St, Springfield',
            'sunset_inactive_days' => 90,
        ]);
        $other = User::factory()->create();
        $other->client->update(['timezone' => 'Asia/Tokyo', 'postal_address' => 'Other Corp, Tokyo']);

        ByoMailerServer::actingAs($user)->tool(ViewAccountProfileTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('user_name', 'Ada Admin')
                ->where('timezone', 'America/Chicago')
                ->where('postal_address', '1 Main St, Springfield')
                ->where('has_postal_address', true)
                ->where('sunset_inactive_days', 90)
                ->where('can_edit', true)
                ->missing('sunset_preview')
                ->etc())
            ->assertDontSee('Other Corp');
    }

    public function test_view_account_profile_returns_the_cached_sunset_preview_on_request(): void
    {
        $user = User::factory()->create();
        $user->client->update(['sunset_inactive_days' => 60]);
        Cache::put(SunsetPolicy::previewCacheKey($user->client_id, 60), 17, 600);

        ByoMailerServer::actingAs($user)->tool(ViewAccountProfileTool::class, ['include_sunset_preview' => true])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('sunset_preview', ['count' => 17, 'days' => 60])
                ->etc());
    }

    public function test_a_viewer_can_read_the_profile_but_cannot_edit_it(): void
    {
        $viewer = $this->member(UserRole::Viewer);

        ByoMailerServer::actingAs($viewer)->tool(ViewAccountProfileTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('can_edit', false)->etc());
    }

    public function test_update_account_profile_saves_the_client_settings(): void
    {
        $user = User::factory()->create(['name' => 'Ada Admin', 'email' => 'ada@example.com']);
        $user->client->update(['timezone' => 'UTC', 'postal_address' => null, 'sunset_inactive_days' => null]);

        ByoMailerServer::actingAs($user)->tool(UpdateAccountProfileTool::class, [
            'postal_address' => '  1 Main St, Springfield  ',
            'timezone' => 'Europe/Madrid',
            'sunset_inactive_days' => 180,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('postal_address', '1 Main St, Springfield')
            ->where('has_postal_address', true)
            ->where('timezone', 'Europe/Madrid')
            ->where('sunset_inactive_days', 180)
            ->where('updated', ['timezone', 'sunset_inactive_days', 'postal_address'])
            ->etc());

        $client = $user->client->fresh();
        $this->assertSame('1 Main St, Springfield', $client->postal_address);
        $this->assertSame('Europe/Madrid', $client->timezone);
        $this->assertSame(180, $client->sunset_inactive_days);

        $user->refresh();
        $this->assertSame('Ada Admin', $user->name);
        $this->assertSame('ada@example.com', $user->email);
    }

    public function test_update_account_profile_changes_only_the_sent_fields_and_can_clear_them(): void
    {
        $user = User::factory()->create();
        $user->client->update(['timezone' => 'America/Chicago', 'postal_address' => 'Old address', 'sunset_inactive_days' => 90]);

        ByoMailerServer::actingAs($user)->tool(UpdateAccountProfileTool::class, [
            'postal_address' => '',
            'sunset_inactive_days' => null,
        ])->assertOk();

        $client = $user->client->fresh();
        $this->assertNull($client->postal_address);
        $this->assertNull($client->sunset_inactive_days);
        $this->assertSame('America/Chicago', $client->timezone);
    }

    public function test_update_account_profile_uses_the_validation_of_the_profile_form(): void
    {
        $user = User::factory()->create();
        $user->client->update(['timezone' => 'UTC', 'postal_address' => 'Kept address', 'sunset_inactive_days' => 90]);

        foreach ([
            ['sunset_inactive_days' => 29],
            ['sunset_inactive_days' => 731],
            ['timezone' => 'Mars/Olympus'],
            ['timezone' => null],
            ['postal_address' => str_repeat('a', 501)],
        ] as $arguments) {
            ByoMailerServer::actingAs($user)->tool(UpdateAccountProfileTool::class, $arguments)->assertHasErrors();
        }

        $client = $user->client->fresh();
        $this->assertSame('UTC', $client->timezone);
        $this->assertSame('Kept address', $client->postal_address);
        $this->assertSame(90, $client->sunset_inactive_days);
    }

    public function test_update_account_profile_needs_at_least_one_field_and_ignores_the_email(): void
    {
        $user = User::factory()->create(['email' => 'kept@example.com']);

        ByoMailerServer::actingAs($user)->tool(UpdateAccountProfileTool::class, ['email' => 'new@example.com'])
            ->assertHasErrors(['Send at least one field to change']);

        $this->assertSame('kept@example.com', $user->fresh()->email);
    }

    public function test_a_viewer_cannot_update_the_account_profile(): void
    {
        $viewer = $this->member(UserRole::Viewer);
        $viewer->client->update(['postal_address' => null]);

        ByoMailerServer::actingAs($viewer)->tool(UpdateAccountProfileTool::class, [
            'postal_address' => 'Viewer address',
        ])->assertHasErrors();

        $this->assertNull($viewer->client->fresh()->postal_address);
    }

    public function test_an_editor_can_update_the_account_profile(): void
    {
        $editor = $this->member(UserRole::Editor);

        ByoMailerServer::actingAs($editor)->tool(UpdateAccountProfileTool::class, [
            'postal_address' => 'Editor address',
        ])->assertOk();

        $this->assertSame('Editor address', $editor->client->fresh()->postal_address);
    }

    public function test_update_account_profile_never_changes_another_client(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $other->client->update(['timezone' => 'Asia/Tokyo', 'postal_address' => 'Other Corp, Tokyo', 'sunset_inactive_days' => 365]);

        ByoMailerServer::actingAs($user)->tool(UpdateAccountProfileTool::class, [
            'client_id' => $other->client_id,
            'postal_address' => 'Mine',
            'timezone' => 'Europe/Madrid',
            'sunset_inactive_days' => 30,
        ])->assertOk();

        $this->assertSame('Mine', $user->client->fresh()->postal_address);

        $otherClient = Client::query()->findOrFail($other->client_id);
        $this->assertSame('Other Corp, Tokyo', $otherClient->postal_address);
        $this->assertSame('Asia/Tokyo', $otherClient->timezone);
        $this->assertSame(365, $otherClient->sunset_inactive_days);
    }

    public function test_the_mcp_postal_address_warning_names_the_tool_and_the_dashboard_text_does_not_change(): void
    {
        $this->assertStringContainsString('update-account-profile-tool', UpdateAccountProfileTool::POSTAL_ADDRESS_WARNING);
        $this->assertStringContainsString('{{ postal_address }}', UpdateAccountProfileTool::POSTAL_ADDRESS_WARNING);
        $this->assertStringNotContainsString('update-account-profile-tool', SendableContentCheck::POSTAL_ADDRESS_WARNING);
        $this->assertStringContainsString('Settings, Profile', SendableContentCheck::POSTAL_ADDRESS_WARNING);
    }

    private function member(UserRole $role): User
    {
        $admin = User::factory()->create();

        return User::factory()->create([
            'client_id' => $admin->client_id,
            'role' => $role->value,
        ]);
    }
}
