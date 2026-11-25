<?php

namespace Tests\Feature;

use App\Models\EmailCredential;
use App\Models\User;
use App\Services\SesClientManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class FetchSesQuotaCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_the_user_option_selects_the_credential_of_the_client_of_the_user(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->for($owner->client)->create();
        EmailCredential::factory()->for($owner)->create();
        EmailCredential::factory()->create();

        $clientManager = Mockery::mock(SesClientManager::class);
        $clientManager->shouldReceive('fetchAndUpdateQuota')
            ->once()
            ->with(Mockery::on(fn (User $user): bool => $user->is($owner)))
            ->andReturn([
                'production_access_enabled' => true,
                'sent_last_24_hours' => 10,
                'max_24_hour_send' => 50000,
                'max_send_rate' => 14,
            ]);
        $this->app->instance(SesClientManager::class, $clientManager);

        $this->artisan('ses:fetch-quota', ['--user' => $teammate->id])
            ->expectsOutputToContain("User {$owner->id}: Production - 10/50000 sent")
            ->assertSuccessful();
    }
}
