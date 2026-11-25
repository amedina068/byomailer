<?php

namespace Tests\Unit;

use App\Models\EmailCredential;
use App\Models\User;
use App\Services\SesClientManager;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SesClientManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mailer_config_for_user_prefers_saved_credentials(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAUSERACCESS123456789',
            'secret_key' => 'user-secret-key-1234567890',
            'region' => 'eu-north-1',
        ]);

        $manager = app(SesClientManager::class);

        $config = $manager->mailerConfigFor($user);

        $this->assertSame('ses', $config['transport']);
        $this->assertSame('AKIAUSERACCESS123456789', $config['key']);
        $this->assertSame('user-secret-key-1234567890', $config['secret']);
        $this->assertSame('eu-north-1', $config['region']);
    }

    public function test_mailer_config_does_not_include_configuration_set_name(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withConfigurationSet()->create([
            'access_key' => 'AKIAUSERACCESS123456789',
            'secret_key' => 'user-secret-key-1234567890',
            'region' => 'eu-north-1',
        ]);

        $manager = app(SesClientManager::class);

        $config = $manager->mailerConfigFor($user);

        $this->assertArrayNotHasKey('options', $config);
    }

    public function test_fetch_and_update_quota_stores_enforcement_status(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $sesClient = Mockery::mock(SesV2Client::class);
        $sesClient->shouldReceive('getAccount')->once()->andReturn(new Result([
            'ProductionAccessEnabled' => true,
            'SendingEnabled' => true,
            'EnforcementStatus' => 'PROBATION',
            'SendQuota' => [
                'Max24HourSend' => 50000,
                'MaxSendRate' => 14,
                'SentLast24Hours' => 10,
            ],
        ]));

        $manager = Mockery::mock(SesClientManager::class, [app('config')])->makePartial();
        $manager->shouldReceive('v2ClientFor')->with($user)->andReturn($sesClient);

        $quota = $manager->fetchAndUpdateQuota($user);

        $this->assertSame('PROBATION', $quota['enforcement_status']);
        $this->assertSame('PROBATION', $user->sesCredential()->first()->enforcement_status);
        $this->assertNotNull($user->sesCredential()->first()->quota_fetched_at);
    }
}
