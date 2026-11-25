<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\UserStorageSetting;
use App\Services\SsrfGuard;
use App\Services\StorageConnectionTester;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorageSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_storage_settings_page_requires_authentication(): void
    {
        $this->get(route('storage-setting.index'))->assertRedirect(route('login'));
    }

    public function test_storage_settings_page_is_displayed_for_user_without_config(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('storage-setting.index'));

        $response
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Storage')
                ->where('setting', null)
            );
    }

    public function test_storage_settings_page_shows_existing_config(): void
    {
        $user = User::factory()->create();
        UserStorageSetting::factory()->verified()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('storage-setting.index'));

        $response
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Storage')
                ->has('setting')
                ->where('setting.is_verified', true)
            );
    }

    public function test_user_can_save_storage_settings_when_connection_succeeds(): void
    {
        $user = User::factory()->create();

        $this->mock(StorageConnectionTester::class)
            ->shouldReceive('test')
            ->once()
            ->andReturn(['success' => true]);

        $response = $this->actingAs($user)->post(route('storage-setting.store'), [
            'provider' => 's3',
            'bucket' => 'my-bucket',
            'region' => 'us-east-1',
            'public_base_url' => 'https://cdn.example.com',
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('user_storage_settings', [
            'user_id' => $user->id,
            'provider' => 's3',
            'bucket' => 'my-bucket',
            'region' => 'us-east-1',
            'is_verified' => true,
        ]);
    }

    public function test_storage_is_not_verified_when_connection_fails(): void
    {
        $user = User::factory()->create();

        $this->mock(StorageConnectionTester::class)
            ->shouldReceive('test')
            ->once()
            ->andReturn(['success' => false, 'error' => 'Write failed: Access Denied.']);

        $response = $this->actingAs($user)->post(route('storage-setting.store'), [
            'provider' => 's3',
            'bucket' => 'my-bucket',
            'region' => 'us-east-1',
            'public_base_url' => 'https://cdn.example.com',
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);

        $response->assertSessionHasErrors('connection');

        $this->assertDatabaseHas('user_storage_settings', [
            'user_id' => $user->id,
            'is_verified' => false,
        ]);
    }

    public function test_r2_provider_requires_endpoint(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('storage-setting.store'), [
            'provider' => 'r2',
            'bucket' => 'my-bucket',
            'region' => 'auto',
            'public_base_url' => 'https://cdn.example.com',
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            // endpoint missing
        ]);

        $response->assertSessionHasErrors('endpoint');
    }

    public function test_public_base_url_must_use_https(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('storage-setting.store'), [
            'provider' => 's3',
            'bucket' => 'my-bucket',
            'region' => 'us-east-1',
            'public_base_url' => 'http://cdn.example.com',
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);

        $response->assertSessionHasErrors('public_base_url');
    }

    public function test_user_can_update_existing_storage_settings(): void
    {
        $user = User::factory()->create();
        UserStorageSetting::factory()->verified()->create(['user_id' => $user->id, 'bucket' => 'old-bucket']);

        $this->mock(StorageConnectionTester::class)
            ->shouldReceive('test')
            ->once()
            ->andReturn(['success' => true]);

        $this->actingAs($user)->post(route('storage-setting.store'), [
            'provider' => 's3',
            'bucket' => 'new-bucket',
            'region' => 'eu-west-1',
            'public_base_url' => 'https://new-cdn.example.com',
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);

        $this->assertDatabaseHas('user_storage_settings', ['user_id' => $user->id, 'bucket' => 'new-bucket']);
        $this->assertDatabaseMissing('user_storage_settings', ['bucket' => 'old-bucket']);
    }

    public function test_user_can_delete_storage_settings(): void
    {
        $user = User::factory()->create();
        UserStorageSetting::factory()->verified()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->delete(route('storage-setting.destroy'))
            ->assertRedirect();

        $this->assertDatabaseMissing('user_storage_settings', ['user_id' => $user->id]);
    }

    public function test_test_connection_returns_error_when_no_config(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('storage-setting.test'))
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_test_connection_returns_success(): void
    {
        $user = User::factory()->create();
        UserStorageSetting::factory()->verified()->create(['user_id' => $user->id]);

        $this->mock(StorageConnectionTester::class)
            ->shouldReceive('test')
            ->once()
            ->andReturn(['success' => true]);

        $this->actingAs($user)
            ->postJson(route('storage-setting.test'))
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_provider_must_be_valid(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('storage-setting.store'), [
            'provider' => 'gcs',
            'bucket' => 'my-bucket',
            'region' => 'us-east-1',
            'public_base_url' => 'https://cdn.example.com',
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);

        $response->assertSessionHasErrors('provider');
    }

    public function test_endpoint_must_use_https(): void
    {
        $this->assertEndpointIsRefused('http://93.184.216.34');
    }

    public function test_endpoint_on_a_loopback_address_is_refused(): void
    {
        $this->assertEndpointIsRefused('https://127.0.0.1:9000');
    }

    public function test_endpoint_host_that_resolves_to_a_private_address_is_refused(): void
    {
        $this->useDnsResolver(fn (string $host): string => '169.254.169.254');

        $this->assertEndpointIsRefused('https://storage.example.test');
    }

    public function test_endpoint_host_that_resolves_to_a_public_address_is_accepted(): void
    {
        $this->useDnsResolver(fn (string $host): string => '93.184.216.34');
        $this->mock(StorageConnectionTester::class)
            ->shouldReceive('test')
            ->once()
            ->andReturn(['success' => true]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('storage-setting.store'), $this->r2Payload('https://account.r2.example.test'));

        $response->assertSessionHasNoErrors();
    }

    public function test_connection_tester_refuses_a_saved_endpoint_that_now_resolves_to_a_private_address(): void
    {
        $this->useDnsResolver(fn (string $host): string => '10.0.0.5');
        $setting = UserStorageSetting::factory()->create(['endpoint' => 'https://storage.example.test']);

        $result = app(StorageConnectionTester::class)->test($setting);

        $this->assertFalse($result['success']);
        $this->assertSame('The storage endpoint must be a public https URL.', $result['error']);
    }

    private function assertEndpointIsRefused(string $endpoint): void
    {
        $this->mock(StorageConnectionTester::class)->shouldNotReceive('test');

        $response = $this->actingAs(User::factory()->create())
            ->post(route('storage-setting.store'), $this->r2Payload($endpoint));

        $response->assertSessionHasErrors(['endpoint' => 'The endpoint must be a public https URL.']);
        $this->assertDatabaseCount('user_storage_settings', 0);
    }

    /**
     * @return array<string, string>
     */
    private function r2Payload(string $endpoint): array
    {
        return [
            'provider' => 'r2',
            'bucket' => 'my-bucket',
            'region' => 'auto',
            'public_base_url' => 'https://cdn.example.com',
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'endpoint' => $endpoint,
        ];
    }

    /**
     * @param  Closure(string): (string|false)  $resolver
     */
    private function useDnsResolver(Closure $resolver): void
    {
        $guard = new SsrfGuard;
        $guard->setDnsResolver($resolver);
        $this->app->instance(SsrfGuard::class, $guard);
    }
}
