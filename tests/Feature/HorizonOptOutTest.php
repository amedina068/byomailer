<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\HorizonServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\HorizonServiceProvider as HorizonPackageServiceProvider;
use Tests\TestCase;

/**
 * Horizon needs Redis. The Docker image runs the database queue, so Horizon
 * has no route and no command there. An install with the redis queue (the
 * production instance of the owner) loads Horizon as before.
 */
class HorizonOptOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_is_not_loaded_without_the_redis_queue(): void
    {
        $this->assertNotSame('redis', config('queue.default'));
        $this->assertFalse($this->app->providerIsLoaded(HorizonPackageServiceProvider::class));
        $this->assertFalse($this->app->providerIsLoaded(HorizonServiceProvider::class));
    }

    public function test_the_horizon_page_does_not_exist_without_the_redis_queue(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);

        $this->actingAs($owner)->get('/horizon')->assertNotFound();
    }

    public function test_the_horizon_command_does_not_exist_without_the_redis_queue(): void
    {
        $this->assertArrayNotHasKey('horizon', Artisan::all());
    }

    public function test_the_bootstrap_providers_add_horizon_only_with_the_redis_queue(): void
    {
        $horizon = [HorizonPackageServiceProvider::class, HorizonServiceProvider::class];

        config(['queue.default' => 'redis']);
        $this->assertEmpty(array_diff($horizon, require base_path('bootstrap/providers.php')));

        foreach (['database', 'sync'] as $queue) {
            config(['queue.default' => $queue]);
            $this->assertEmpty(array_intersect($horizon, require base_path('bootstrap/providers.php')), "Horizon loaded with the queue {$queue}.");
        }
    }
}
