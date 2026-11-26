<?php

namespace Tests\Feature;

use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\SesConfigurationSetService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class EnableSesEventDestinationsCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_it_enables_the_disabled_destinations_of_each_configuration_set(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create(['region' => 'us-east-1']);
        EmailIdentity::factory()->for($user)->withConfigurationSet('mailautomation-1-abc')->create(['region' => 'us-east-1']);

        $configService = Mockery::mock(SesConfigurationSetService::class);
        $configService->shouldReceive('enableDisabledSnsEventDestinations')
            ->once()
            ->with('mailautomation-1-abc', $credential->access_key, $credential->secret_key, 'us-east-1', null)
            ->andReturn(['success' => true, 'enabled' => ['sns-webhook']]);
        $this->app->instance(SesConfigurationSetService::class, $configService);

        $this->artisan('ses:enable-event-destinations')
            ->expectsOutputToContain('mailautomation-1-abc / sns-webhook: enabled')
            ->expectsOutputToContain('Enabled 1 event destination(s). Failed: 0.')
            ->assertSuccessful();

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('EnableSesEventDestinationsCommand: enabled an SES event destination that AWS disabled', [
                'client_id' => $user->client_id,
                'configuration_set' => 'mailautomation-1-abc',
                'event_destination' => 'sns-webhook',
            ]);
    }

    public function test_it_checks_a_shared_configuration_set_one_time(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        EmailIdentity::factory()->for($user)->withConfigurationSet('mailautomation-1-shared')->count(2)->create();

        $configService = Mockery::mock(SesConfigurationSetService::class);
        $configService->shouldReceive('enableDisabledSnsEventDestinations')
            ->once()
            ->andReturn(['success' => true, 'enabled' => []]);
        $this->app->instance(SesConfigurationSetService::class, $configService);

        $this->artisan('ses:enable-event-destinations')->assertSuccessful();
    }

    public function test_it_skips_identities_without_a_configuration_set(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        EmailIdentity::factory()->for($user)->create(['configuration_set_name' => null]);

        $configService = Mockery::mock(SesConfigurationSetService::class);
        $configService->shouldNotReceive('enableDisabledSnsEventDestinations');
        $this->app->instance(SesConfigurationSetService::class, $configService);

        $this->artisan('ses:enable-event-destinations')->assertSuccessful();
    }

    public function test_it_uses_the_credential_of_the_client_that_owns_the_identity(): void
    {
        $owner = User::factory()->create();
        $ownerCredential = EmailCredential::factory()->for($owner)->create();
        EmailIdentity::factory()->for($owner)->withConfigurationSet('mailautomation-owner')->create();

        $other = User::factory()->create();
        $otherCredential = EmailCredential::factory()->for($other)->create();
        EmailIdentity::factory()->for($other)->withConfigurationSet('mailautomation-other')->create();

        $configService = Mockery::mock(SesConfigurationSetService::class);
        $configService->shouldReceive('enableDisabledSnsEventDestinations')
            ->once()
            ->with('mailautomation-owner', $ownerCredential->access_key, Mockery::any(), Mockery::any(), null)
            ->andReturn(['success' => true, 'enabled' => []]);
        $configService->shouldReceive('enableDisabledSnsEventDestinations')
            ->once()
            ->with('mailautomation-other', $otherCredential->access_key, Mockery::any(), Mockery::any(), null)
            ->andReturn(['success' => true, 'enabled' => []]);
        $this->app->instance(SesConfigurationSetService::class, $configService);

        $this->artisan('ses:enable-event-destinations')->assertSuccessful();
    }

    public function test_it_continues_after_a_failure_and_returns_failure(): void
    {
        $failing = User::factory()->create();
        EmailCredential::factory()->for($failing)->create();
        EmailIdentity::factory()->for($failing)->withConfigurationSet('mailautomation-failing')->create();

        $working = User::factory()->create();
        EmailCredential::factory()->for($working)->create();
        EmailIdentity::factory()->for($working)->withConfigurationSet('mailautomation-working')->create();

        $configService = Mockery::mock(SesConfigurationSetService::class);
        $configService->shouldReceive('enableDisabledSnsEventDestinations')
            ->with('mailautomation-failing', Mockery::any(), Mockery::any(), Mockery::any(), null)
            ->once()
            ->andReturn(['success' => false, 'error' => 'Access denied']);
        $configService->shouldReceive('enableDisabledSnsEventDestinations')
            ->with('mailautomation-working', Mockery::any(), Mockery::any(), Mockery::any(), null)
            ->once()
            ->andReturn(['success' => true, 'enabled' => ['sns-webhook']]);
        $this->app->instance(SesConfigurationSetService::class, $configService);

        $this->artisan('ses:enable-event-destinations')
            ->expectsOutputToContain('mailautomation-failing: Access denied')
            ->expectsOutputToContain('Enabled 1 event destination(s). Failed: 1.')
            ->assertFailed();
    }

    public function test_it_runs_every_hour(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'ses:enable-event-destinations'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }
}
