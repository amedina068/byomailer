<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\SesClientManager;
use App\Services\SesConfigurationSetService;
use App\Services\SesPermissionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class SyncConfigurationSetsCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private function mockPermissions(): void
    {
        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('fetchAndUpdatePermissions')
            ->andReturn([
                'can_create_template' => true,
                'can_delete_template' => true,
                'can_send_bulk_email' => true,
                'can_send_email' => true,
            ]);

        $this->app->instance(SesClientManager::class, $mockClientManager);
    }

    public function test_it_syncs_tracking_settings_from_api(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'open_tracking_enabled' => true,
            'click_tracking_enabled' => true,
        ]);

        $mockConfigService = Mockery::mock(SesConfigurationSetService::class);
        $mockConfigService->shouldReceive('fetchTrackingStatus')
            ->once()
            ->andReturn([
                'success' => true,
                'open_tracking_enabled' => false,
                'click_tracking_enabled' => true,
            ]);

        $this->app->instance(SesConfigurationSetService::class, $mockConfigService);
        $this->mockPermissions();

        $this->artisan('ses:sync-configuration-sets')
            ->assertSuccessful();

        $identity = $user->emailIdentities()->withoutGlobalScopes()->first();
        $this->assertFalse($identity->open_tracking_enabled);
        $this->assertTrue($identity->click_tracking_enabled);
    }

    public function test_it_skips_tracking_sync_for_identities_without_configuration_set(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => null,
        ]);

        $mockConfigService = Mockery::mock(SesConfigurationSetService::class);
        $mockConfigService->shouldNotReceive('fetchTrackingStatus');

        $this->app->instance(SesConfigurationSetService::class, $mockConfigService);
        $this->mockPermissions();

        $this->artisan('ses:sync-configuration-sets')
            ->assertSuccessful();
    }

    public function test_it_handles_tracking_api_failure_gracefully(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'open_tracking_enabled' => true,
            'click_tracking_enabled' => true,
        ]);

        $mockConfigService = Mockery::mock(SesConfigurationSetService::class);
        $mockConfigService->shouldReceive('fetchTrackingStatus')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Configuration set not found',
            ]);

        $this->app->instance(SesConfigurationSetService::class, $mockConfigService);
        $this->mockPermissions();

        $this->artisan('ses:sync-configuration-sets')
            ->assertSuccessful();

        $identity = $user->emailIdentities()->withoutGlobalScopes()->first();
        $this->assertTrue($identity->open_tracking_enabled);
        $this->assertTrue($identity->click_tracking_enabled);
    }

    public function test_it_can_filter_by_user_id(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        EmailCredential::factory()->for($user1)->create();
        EmailCredential::factory()->for($user2)->create();
        EmailIdentity::factory()->for($user1)->withConfigurationSet()->create([
            'open_tracking_enabled' => true,
            'click_tracking_enabled' => true,
        ]);
        EmailIdentity::factory()->for($user2)->withConfigurationSet()->create([
            'open_tracking_enabled' => true,
            'click_tracking_enabled' => true,
        ]);

        $mockConfigService = Mockery::mock(SesConfigurationSetService::class);
        $mockConfigService->shouldReceive('fetchTrackingStatus')
            ->once()
            ->andReturn([
                'success' => true,
                'open_tracking_enabled' => false,
                'click_tracking_enabled' => false,
            ]);

        $this->app->instance(SesConfigurationSetService::class, $mockConfigService);

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('fetchAndUpdatePermissions')
            ->once()
            ->andReturn([
                'can_create_template' => true,
                'can_delete_template' => true,
                'can_send_bulk_email' => true,
                'can_send_email' => true,
            ]);

        $this->app->instance(SesClientManager::class, $mockClientManager);

        $this->artisan('ses:sync-configuration-sets', ['--user' => $user1->id])
            ->assertSuccessful();

        $this->assertFalse($user1->emailIdentities()->withoutGlobalScopes()->first()->fresh()->open_tracking_enabled);
        $this->assertTrue($user2->emailIdentities()->withoutGlobalScopes()->first()->fresh()->open_tracking_enabled);
    }

    public function test_it_syncs_permissions_from_api(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_create_template' => false,
            'can_delete_template' => false,
            'can_send_bulk_email' => false,
            'can_send_email' => false,
            'permissions_checked_at' => null,
        ]);

        $mockConfigService = Mockery::mock(SesConfigurationSetService::class);
        $this->app->instance(SesConfigurationSetService::class, $mockConfigService);

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('fetchAndUpdatePermissions')
            ->once()
            ->andReturnUsing(function ($user) {
                $credential = $user->sesCredential;
                $credential->update([
                    'can_create_template' => true,
                    'can_delete_template' => true,
                    'can_send_bulk_email' => true,
                    'can_send_email' => true,
                    'permissions_checked_at' => now(),
                ]);

                return [
                    'can_create_template' => true,
                    'can_delete_template' => true,
                    'can_send_bulk_email' => true,
                    'can_send_email' => true,
                ];
            });

        $this->app->instance(SesClientManager::class, $mockClientManager);

        $this->artisan('ses:sync-configuration-sets')
            ->assertSuccessful();

        $credential = $user->sesCredential()->withoutGlobalScopes()->first();
        $this->assertTrue($credential->can_create_template);
        $this->assertTrue($credential->can_send_email);
        $this->assertNotNull($credential->permissions_checked_at);
    }

    public function test_it_reports_no_credentials_found(): void
    {
        $this->artisan('ses:sync-configuration-sets')
            ->expectsOutputToContain('No users with SES credentials found.')
            ->assertSuccessful();
    }

    public function test_it_requeues_blocked_sequence_mails_when_permissions_restored(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => false,
            'permissions_checked_at' => now(),
        ]);

        $contact = Contact::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create();

        $contactMail = ContactMail::factory()
            ->for($contact, 'contact')
            ->forSequenceStep($step)
            ->create([
                'status' => 'failed',
                'failure_reason' => SesPermissionChecker::FAILURE_REASON,
                'sent_at' => null,
            ]);

        $mockConfigService = Mockery::mock(SesConfigurationSetService::class);
        $this->app->instance(SesConfigurationSetService::class, $mockConfigService);

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('fetchAndUpdatePermissions')
            ->once()
            ->andReturnUsing(function ($user) {
                $user->sesCredential->update([
                    'can_send_email' => true,
                    'permissions_checked_at' => now(),
                ]);

                return [
                    'can_create_template' => true,
                    'can_delete_template' => true,
                    'can_send_bulk_email' => true,
                    'can_send_email' => true,
                ];
            });

        $this->app->instance(SesClientManager::class, $mockClientManager);

        $this->artisan('ses:sync-configuration-sets')
            ->assertSuccessful();

        $contactMail->refresh();
        $this->assertSame('pending', $contactMail->status);
        $this->assertNull($contactMail->failure_reason);
    }

    public function test_it_does_not_requeue_when_permissions_remain_missing(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => false,
            'permissions_checked_at' => now(),
        ]);

        $contact = Contact::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create();

        $contactMail = ContactMail::factory()
            ->for($contact, 'contact')
            ->forSequenceStep($step)
            ->create([
                'status' => 'failed',
                'failure_reason' => SesPermissionChecker::FAILURE_REASON,
                'sent_at' => null,
            ]);

        $mockConfigService = Mockery::mock(SesConfigurationSetService::class);
        $this->app->instance(SesConfigurationSetService::class, $mockConfigService);

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('fetchAndUpdatePermissions')
            ->once()
            ->andReturn([
                'can_create_template' => true,
                'can_delete_template' => true,
                'can_send_bulk_email' => true,
                'can_send_email' => false,
            ]);

        $this->app->instance(SesClientManager::class, $mockClientManager);

        $this->artisan('ses:sync-configuration-sets')
            ->assertSuccessful();

        $contactMail->refresh();
        $this->assertSame('failed', $contactMail->status);
        $this->assertSame(SesPermissionChecker::FAILURE_REASON, $contactMail->failure_reason);
    }
}
