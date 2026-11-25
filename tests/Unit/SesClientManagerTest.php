<?php

namespace Tests\Unit;

use App\Models\EmailCredential;
use App\Models\User;
use App\Services\SesClientManager;
use App\Services\SesCredentials;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\FakesAwsRole;
use Tests\TestCase;

class SesClientManagerTest extends TestCase
{
    use FakesAwsRole, RefreshDatabase;

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

        $manager = Mockery::mock(SesClientManager::class, [app('config'), app(SesCredentials::class)])->makePartial();
        $manager->shouldReceive('v2ClientFor')->with($user)->andReturn($sesClient);

        $quota = $manager->fetchAndUpdateQuota($user);

        $this->assertSame('PROBATION', $quota['enforcement_status']);
        $this->assertSame('PROBATION', $user->sesCredential()->first()->enforcement_status);
        $this->assertNotNull($user->sesCredential()->first()->quota_fetched_at);
    }

    public function test_mailer_config_for_a_role_carries_the_temporary_credentials(): void
    {
        $this->fakeAwsRole();
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->role()->create(['region' => 'eu-west-1']);

        $config = app(SesClientManager::class)->mailerConfigFor($user);

        $this->assertSame(self::ROLE_ACCESS_KEY, $config['key']);
        $this->assertSame(self::ROLE_SECRET_KEY, $config['secret']);
        $this->assertSame(self::ROLE_SESSION_TOKEN, $config['token']);
        $this->assertSame('eu-west-1', $config['region']);
    }

    public function test_mailer_config_for_keys_has_no_token(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $this->assertArrayNotHasKey('token', app(SesClientManager::class)->mailerConfigFor($user));
    }

    public function test_an_sdk_client_for_a_role_signs_with_the_temporary_credentials(): void
    {
        $this->fakeAwsRole();
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->role()->create();

        $credentials = app(SesClientManager::class)->v2ClientFor($user)->getCredentials()->wait();

        $this->assertSame(self::ROLE_ACCESS_KEY, $credentials->getAccessKeyId());
        $this->assertSame(self::ROLE_SESSION_TOKEN, $credentials->getSecurityToken());
    }

    /**
     * An IAM call fails in tests, because `phpunit.xml` blocks AWS. So the
     * test passes only when the role path makes no IAM call.
     */
    public function test_the_permission_check_of_a_role_does_not_simulate_the_policy(): void
    {
        $sts = $this->fakeAwsRole();
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $credential = EmailCredential::factory()->for($user)->role()->create([
            'can_send_bulk_email' => false,
            'permissions_checked_at' => null,
        ]);

        $permissions = app(SesClientManager::class)->fetchAndUpdatePermissions($user);

        $this->assertSame([
            'can_create_template' => true,
            'can_delete_template' => true,
            'can_send_bulk_email' => true,
            'can_send_email' => true,
        ], $permissions);
        $this->assertNotNull($credential->fresh()->permissions_checked_at);
        $sts->shouldHaveReceived('assumeRole')->once();
    }
}
