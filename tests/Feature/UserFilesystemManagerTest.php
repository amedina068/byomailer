<?php

namespace Tests\Feature;

use App\Exceptions\SsrfException;
use App\Models\User;
use App\Models\UserStorageSetting;
use App\Services\SsrfGuard;
use App\Services\UserFilesystemManager;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFilesystemManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_custom_storage_returns_default_disk(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $manager = new UserFilesystemManager;

        $this->assertFalse($manager->hasCustomStorage($user));
        $this->assertEquals(config('media-library.disk_name'), $manager->diskFor($user));
    }

    public function test_user_with_unverified_storage_returns_default_disk(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        UserStorageSetting::factory()->create(['user_id' => $user->id, 'is_verified' => false]);
        $user->load('storageSetting');

        $manager = new UserFilesystemManager;

        $this->assertFalse($manager->hasCustomStorage($user));
        $this->assertEquals(config('media-library.disk_name'), $manager->diskFor($user));
    }

    public function test_user_with_verified_storage_returns_user_disk(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        UserStorageSetting::factory()->verified()->create(['user_id' => $user->id]);
        $user->load('storageSetting');

        $manager = new UserFilesystemManager;

        $this->assertTrue($manager->hasCustomStorage($user));
        $this->assertEquals("user-media-{$user->id}", $manager->diskFor($user));
    }

    public function test_disk_config_is_registered_for_verified_user(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        UserStorageSetting::factory()->verified()->create([
            'user_id' => $user->id,
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
        ]);
        $user->load('storageSetting');

        $manager = new UserFilesystemManager;
        $diskName = $manager->diskFor($user);

        $diskConfig = config("filesystems.disks.{$diskName}");
        $this->assertNotNull($diskConfig);
        $this->assertEquals('s3', $diskConfig['driver']);
        $this->assertEquals('test-bucket', $diskConfig['bucket']);
        $this->assertEquals('us-east-1', $diskConfig['region']);
    }

    public function test_disk_is_only_registered_once_per_request(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        UserStorageSetting::factory()->verified()->create(['user_id' => $user->id]);
        $user->load('storageSetting');

        $manager = new UserFilesystemManager;

        $disk1 = $manager->diskFor($user);
        $disk2 = $manager->diskFor($user);

        $this->assertEquals($disk1, $disk2);
    }

    public function test_r2_endpoint_is_included_in_disk_config(): void
    {
        $this->useDnsResolver(fn (string $host): string => '93.184.216.34');
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        UserStorageSetting::factory()->verified()->r2()->create([
            'user_id' => $user->id,
            'endpoint' => 'https://accountid.r2.cloudflarestorage.com',
        ]);
        $user->load('storageSetting');

        $manager = new UserFilesystemManager;
        $diskName = $manager->diskFor($user);

        $diskConfig = config("filesystems.disks.{$diskName}");
        $this->assertEquals('https://accountid.r2.cloudflarestorage.com', $diskConfig['endpoint']);
        $this->assertSame(
            ['accountid.r2.cloudflarestorage.com:443:93.184.216.34'],
            $diskConfig['http']['curl'][CURLOPT_RESOLVE],
        );
    }

    public function test_endpoint_that_now_resolves_to_a_private_address_is_refused(): void
    {
        $this->useDnsResolver(fn (string $host): string => '127.0.0.1');
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        UserStorageSetting::factory()->verified()->r2()->create([
            'user_id' => $user->id,
            'endpoint' => 'https://accountid.r2.cloudflarestorage.com',
        ]);
        $user->load('storageSetting');

        $this->expectException(SsrfException::class);

        (new UserFilesystemManager)->diskFor($user);
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
