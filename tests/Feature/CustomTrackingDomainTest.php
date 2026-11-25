<?php

namespace Tests\Feature;

use App\Console\Commands\CheckCustomTrackingDomainDns;
use App\Jobs\VerifyTrackingDomainJob;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\SesConfigurationSetService;
use App\Services\SesCredentials;
use App\Services\TrackingDomainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CustomTrackingDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_custom_tracking_domain(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'region' => 'us-east-1',
        ]);

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'track.example.com',
            ]
        );

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'a_record_ip' => '203.0.113.10',
        ]);

        $identity->refresh();
        $this->assertSame('track.example.com', $identity->custom_tracking_domain);
        $this->assertNull($identity->custom_tracking_domain_verified_at);
    }

    public function test_saving_domain_does_not_call_ses_api(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create();

        $mockService = Mockery::mock(SesConfigurationSetService::class);
        $mockService->shouldNotReceive('putTrackingOptions');
        $this->app->instance(SesConfigurationSetService::class, $mockService);

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'track.example.com',
            ]
        );

        $response->assertOk();
    }

    public function test_user_can_remove_verified_custom_tracking_domain(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'region' => 'us-east-1',
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => now(),
        ]);

        $mockService = Mockery::mock(SesConfigurationSetService::class);
        $mockService->shouldReceive('removeTrackingOptions')
            ->once()
            ->andReturn(['success' => true]);
        $this->app->instance(SesConfigurationSetService::class, $mockService);

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => false,
            ]
        );

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $identity->refresh();
        $this->assertNull($identity->custom_tracking_domain);
        $this->assertNull($identity->custom_tracking_domain_verified_at);
    }

    public function test_removing_unverified_domain_does_not_call_ses_api(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => null,
        ]);

        $mockService = Mockery::mock(SesConfigurationSetService::class);
        $mockService->shouldNotReceive('removeTrackingOptions');
        $this->app->instance(SesConfigurationSetService::class, $mockService);

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => false,
            ]
        );

        $response->assertOk();

        $identity->refresh();
        $this->assertNull($identity->custom_tracking_domain);
    }

    public function test_validation_rejects_invalid_domains(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create();

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'not a valid domain!',
            ]
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['custom_tracking_domain']);
    }

    public function test_validation_requires_domain_when_enabled(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create();

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => null,
            ]
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['custom_tracking_domain']);
    }

    public function test_returns_404_when_no_credential_or_config_set(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => null,
            'configuration_set_created_at' => null,
        ]);

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'track.example.com',
            ]
        );

        $response->assertNotFound()->assertJson(['error' => 'No AWS credentials found. Add your AWS credentials in Settings.']);
    }

    public function test_returns_404_when_identity_has_no_config_set(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => null,
            'configuration_set_created_at' => null,
        ]);

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'track.example.com',
            ]
        );

        $response->assertNotFound();
        $response->assertJson(['error' => 'No configuration set found for this identity.']);
    }

    public function test_remove_returns_error_when_ses_api_fails(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => now(),
        ]);

        $mockService = Mockery::mock(SesConfigurationSetService::class);
        $mockService->shouldReceive('removeTrackingOptions')
            ->once()
            ->andReturn(['success' => false, 'error' => 'Access denied']);
        $this->app->instance(SesConfigurationSetService::class, $mockService);

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => false,
            ]
        );

        $response->assertUnprocessable();
        $response->assertJson(['error' => 'Failed to remove custom tracking domain: Access denied']);
    }

    public function test_check_status_returns_not_verified_when_dns_missing(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'region' => 'us-east-1',
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => null,
        ]);

        $verifier = $this->app->make(TrackingDomainVerifier::class);
        $verifier->setDnsResolver(fn () => false);
        $this->app->instance(TrackingDomainVerifier::class, $verifier);

        $response = $this->actingAs($user)->post(
            route('identities.check-custom-tracking-domain', $identity)
        );

        $response->assertOk();
        $response->assertJson(['verified' => false]);
        $response->assertJsonStructure(['verified', 'checked_at']);
    }

    public function test_check_status_verifies_and_activates_when_dns_matches(): void
    {
        Http::fake(['*/healthz' => Http::response('ok', 200)]);

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'region' => 'us-east-1',
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => null,
        ]);

        $mockService = Mockery::mock(SesConfigurationSetService::class);
        $mockService->shouldReceive('putTrackingOptions')
            ->once()
            ->andReturn(['success' => true]);
        $this->app->instance(SesConfigurationSetService::class, $mockService);

        $verifier = new TrackingDomainVerifier($mockService, app(SesCredentials::class));
        $verifier->setDnsResolver(fn () => [
            ['ip' => '203.0.113.10'],
        ]);
        $this->app->instance(TrackingDomainVerifier::class, $verifier);

        $response = $this->actingAs($user)->post(
            route('identities.check-custom-tracking-domain', $identity)
        );

        $response->assertOk();
        $response->assertJson(['verified' => true]);

        $identity->refresh();
        $this->assertNotNull($identity->custom_tracking_domain_verified_at);
    }

    public function test_verify_fails_when_tls_warm_request_returns_error(): void
    {
        Http::fake(['*/healthz' => Http::response('bad gateway', 502)]);

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'region' => 'us-east-1',
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => null,
        ]);

        $mockService = Mockery::mock(SesConfigurationSetService::class);
        $mockService->shouldNotReceive('putTrackingOptions');
        $this->app->instance(SesConfigurationSetService::class, $mockService);

        $verifier = new TrackingDomainVerifier($mockService, app(SesCredentials::class));
        $verifier->setDnsResolver(fn () => [
            ['ip' => '203.0.113.10'],
        ]);
        $this->app->instance(TrackingDomainVerifier::class, $verifier);

        $response = $this->actingAs($user)->post(
            route('identities.check-custom-tracking-domain', $identity)
        );

        $response->assertUnprocessable();
        $response->assertJsonPath('verified', false);
        $this->assertStringContainsString('status 502', $response->json('error'));

        $identity->refresh();
        $this->assertNull($identity->custom_tracking_domain_verified_at);
        $this->assertNull(Cache::get(TrackingDomainVerifier::tlsAllowCacheKey('track.example.com')));
    }

    public function test_verify_fails_when_tls_warm_request_throws(): void
    {
        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'region' => 'us-east-1',
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => null,
        ]);

        $mockService = Mockery::mock(SesConfigurationSetService::class);
        $mockService->shouldNotReceive('putTrackingOptions');
        $this->app->instance(SesConfigurationSetService::class, $mockService);

        $verifier = new TrackingDomainVerifier($mockService, app(SesCredentials::class));
        $verifier->setDnsResolver(fn () => [
            ['ip' => '203.0.113.10'],
        ]);
        $this->app->instance(TrackingDomainVerifier::class, $verifier);

        $response = $this->actingAs($user)->post(
            route('identities.check-custom-tracking-domain', $identity)
        );

        $response->assertUnprocessable();
        $response->assertJsonPath('verified', false);
        $this->assertStringContainsString('Could not reach', $response->json('error'));

        $this->assertNull(Cache::get(TrackingDomainVerifier::tlsAllowCacheKey('track.example.com')));
    }

    public function test_check_status_returns_error_when_ses_activation_fails(): void
    {
        Http::fake(['*/healthz' => Http::response('ok', 200)]);

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'region' => 'us-east-1',
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => null,
        ]);

        $mockService = Mockery::mock(SesConfigurationSetService::class);
        $mockService->shouldReceive('putTrackingOptions')
            ->once()
            ->andReturn(['success' => false, 'error' => 'Access denied']);
        $this->app->instance(SesConfigurationSetService::class, $mockService);

        $verifier = new TrackingDomainVerifier($mockService, app(SesCredentials::class));
        $verifier->setDnsResolver(fn () => [
            ['ip' => '203.0.113.10'],
        ]);
        $this->app->instance(TrackingDomainVerifier::class, $verifier);

        $response = $this->actingAs($user)->post(
            route('identities.check-custom-tracking-domain', $identity)
        );

        $response->assertUnprocessable();
        $response->assertJsonPath('verified', false);
        $response->assertJsonPath('error', 'Access denied');

        $this->assertNull(Cache::get(TrackingDomainVerifier::tlsAllowCacheKey('track.example.com')));
    }

    public function test_check_status_returns_cached_result_within_cooldown(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
        ]);

        $cachedResult = [
            'verified' => false,
            'checked_at' => now()->subSeconds(30)->toIso8601String(),
        ];

        Cache::put("tracking-domain-check:{$identity->id}", $cachedResult, 300);

        $response = $this->actingAs($user)->post(
            route('identities.check-custom-tracking-domain', $identity)
        );

        $response->assertOk();
        $response->assertJson($cachedResult);
    }

    public function test_check_status_returns_cached_error_with_422(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
        ]);

        $cachedResult = [
            'verified' => false,
            'error' => 'DNS verified but failed to update SES: Access denied',
            'checked_at' => now()->subSeconds(30)->toIso8601String(),
        ];

        Cache::put("tracking-domain-check:{$identity->id}", $cachedResult, 300);

        $response = $this->actingAs($user)->post(
            route('identities.check-custom-tracking-domain', $identity)
        );

        $response->assertUnprocessable();
        $response->assertJson($cachedResult);
    }

    public function test_saving_domain_clears_check_cache(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'region' => 'us-east-1',
            'custom_tracking_domain' => 'old.example.com',
        ]);

        $cacheKey = "tracking-domain-check:{$identity->id}";
        Cache::put($cacheKey, ['verified' => false, 'checked_at' => now()->toIso8601String()], 300);

        $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'new.example.com',
            ]
        );

        $this->assertNull(Cache::get($cacheKey));
    }

    public function test_removing_domain_clears_check_cache(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => null,
        ]);

        $cacheKey = "tracking-domain-check:{$identity->id}";
        Cache::put($cacheKey, ['verified' => false, 'checked_at' => now()->toIso8601String()], 300);

        $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => false,
            ]
        );

        $this->assertNull(Cache::get($cacheKey));
    }

    public function test_check_status_returns_404_when_no_tracking_domain(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => null,
        ]);

        $response = $this->actingAs($user)->post(
            route('identities.check-custom-tracking-domain', $identity)
        );

        $response->assertNotFound();
    }

    public function test_guest_cannot_access_endpoints(): void
    {
        $this->putJson(route('identities.update-custom-tracking-domain', 999), [
            'enabled' => true,
            'custom_tracking_domain' => 'track.example.com',
        ])->assertUnauthorized();

        $this->postJson(route('identities.check-custom-tracking-domain', 999))
            ->assertUnauthorized();
    }

    public function test_user_cannot_access_other_clients_identity(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $otherUser = User::factory()->create();
        $otherIdentity = EmailIdentity::factory()->for($otherUser)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
        ]);

        $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $otherIdentity),
            ['enabled' => true, 'custom_tracking_domain' => 'track.example.com']
        )->assertNotFound();

        $this->actingAs($user)->postJson(
            route('identities.check-custom-tracking-domain', $otherIdentity)
        )->assertNotFound();
    }

    public function test_changing_verified_domain_removes_old_tracking_options_from_ses(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'region' => 'us-east-1',
            'custom_tracking_domain' => 'old-track.example.com',
            'custom_tracking_domain_verified_at' => now(),
        ]);

        $mockService = Mockery::mock(SesConfigurationSetService::class);
        $mockService->shouldReceive('removeTrackingOptions')
            ->once()
            ->andReturn(['success' => true]);
        $this->app->instance(SesConfigurationSetService::class, $mockService);

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'new-track.example.com',
            ]
        );

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'a_record_ip' => '203.0.113.10',
        ]);

        $identity->refresh();
        $this->assertSame('new-track.example.com', $identity->custom_tracking_domain);
        $this->assertNull($identity->custom_tracking_domain_verified_at);
    }

    public function test_changing_to_same_domain_does_not_remove_tracking_options(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'region' => 'us-east-1',
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => now(),
        ]);

        $mockService = Mockery::mock(SesConfigurationSetService::class);
        $mockService->shouldNotReceive('removeTrackingOptions');
        $this->app->instance(SesConfigurationSetService::class, $mockService);

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'track.example.com',
            ]
        );

        $response->assertOk();
    }

    public function test_changing_unverified_domain_does_not_call_ses_remove(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'region' => 'us-east-1',
            'custom_tracking_domain' => 'old-track.example.com',
            'custom_tracking_domain_verified_at' => null,
        ]);

        $mockService = Mockery::mock(SesConfigurationSetService::class);
        $mockService->shouldNotReceive('removeTrackingOptions');
        $this->app->instance(SesConfigurationSetService::class, $mockService);

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'new-track.example.com',
            ]
        );

        $response->assertOk();

        $identity->refresh();
        $this->assertSame('new-track.example.com', $identity->custom_tracking_domain);
        $this->assertNull($identity->custom_tracking_domain_verified_at);
    }

    public function test_console_command_dispatches_verify_job_per_unverified_identity(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create(['region' => 'us-east-1']);

        $a = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'a.example.com',
            'custom_tracking_domain_verified_at' => null,
        ]);
        $b = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'b.example.com',
            'custom_tracking_domain_verified_at' => null,
        ]);
        EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'c.example.com',
            'custom_tracking_domain_verified_at' => now(),
        ]);

        $this->artisan(CheckCustomTrackingDomainDns::class)->assertSuccessful();

        Bus::assertDispatchedTimes(VerifyTrackingDomainJob::class, 2);
        Bus::assertDispatched(VerifyTrackingDomainJob::class, fn ($job) => $job->emailIdentityId === $a->id);
        Bus::assertDispatched(VerifyTrackingDomainJob::class, fn ($job) => $job->emailIdentityId === $b->id);
        Bus::assertDispatched(VerifyTrackingDomainJob::class, fn ($job) => $job->queue === 'tracking-domains');
    }

    public function test_verify_job_runs_verifier_and_persists_verified_at(): void
    {
        Http::fake(['*/healthz' => Http::response('ok', 200)]);

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create(['region' => 'us-east-1']);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'region' => 'us-east-1',
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => null,
        ]);

        $mockService = Mockery::mock(SesConfigurationSetService::class);
        $mockService->shouldReceive('putTrackingOptions')->once()->andReturn(['success' => true]);
        $this->app->instance(SesConfigurationSetService::class, $mockService);

        $verifier = new TrackingDomainVerifier($mockService, app(SesCredentials::class));
        $verifier->setDnsResolver(fn () => [['ip' => '203.0.113.10']]);
        $this->app->instance(TrackingDomainVerifier::class, $verifier);

        (new VerifyTrackingDomainJob($identity->id))->handle($verifier);

        $this->assertNotNull($identity->fresh()->custom_tracking_domain_verified_at);
    }

    public function test_verify_job_is_noop_when_identity_already_verified(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => now()->subDay(),
        ]);

        $verifier = Mockery::mock(TrackingDomainVerifier::class);
        $verifier->shouldNotReceive('verify');

        (new VerifyTrackingDomainJob($identity->id))->handle($verifier);
    }

    public function test_console_command_skips_already_verified_domains(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'region' => 'us-east-1',
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => now()->subDay(),
        ]);

        $this->artisan(CheckCustomTrackingDomainDns::class)
            ->expectsOutput('No unverified custom tracking domains to check.')
            ->assertSuccessful();

        $identity = $user->emailIdentities()->withoutGlobalScopes()->first();
        $this->assertNotNull($identity->custom_tracking_domain_verified_at);
    }

    public function test_console_command_skips_when_no_tracking_domains(): void
    {
        $this->artisan(CheckCustomTrackingDomainDns::class)
            ->expectsOutput('No unverified custom tracking domains to check.')
            ->assertSuccessful();
    }

    public function test_validation_rejects_domain_already_used_by_another_client(): void
    {
        $otherUser = User::factory()->create();
        EmailIdentity::factory()->for($otherUser)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
        ]);

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create();

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'track.example.com',
            ]
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['custom_tracking_domain']);
    }

    public function test_validation_allows_same_domain_across_identities_in_same_client(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create();

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'track.example.com',
            ]
        );

        $response->assertOk();
    }

    public function test_validation_allows_teammate_in_same_client_to_share_domain(): void
    {
        $owner = User::factory()->create();
        EmailIdentity::factory()->for($owner)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
        ]);

        $teammate = User::factory()->create(['client_id' => $owner->client_id]);
        EmailCredential::factory()->for($teammate)->create();
        $identity = EmailIdentity::factory()->for($teammate)->withConfigurationSet()->create();

        $response = $this->actingAs($teammate)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'track.example.com',
            ]
        );

        $response->assertOk();
    }

    public function test_domain_input_is_lowercased(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create();

        $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'Track.EXAMPLE.com',
            ]
        )->assertOk();

        $this->assertSame('track.example.com', $identity->fresh()->custom_tracking_domain);
    }

    public function test_rotating_domain_forgets_tls_allow_flag_for_old_domain(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'old.example.com',
        ]);

        Cache::put(TrackingDomainVerifier::tlsAllowCacheKey('old.example.com'), true, 600);

        $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            [
                'enabled' => true,
                'custom_tracking_domain' => 'new.example.com',
            ]
        )->assertOk();

        $this->assertNull(Cache::get(TrackingDomainVerifier::tlsAllowCacheKey('old.example.com')));
    }

    public function test_removing_domain_forgets_tls_allow_flag(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => null,
        ]);

        Cache::put(TrackingDomainVerifier::tlsAllowCacheKey('track.example.com'), true, 600);

        $this->actingAs($user)->putJson(
            route('identities.update-custom-tracking-domain', $identity),
            ['enabled' => false]
        )->assertOk();

        $this->assertNull(Cache::get(TrackingDomainVerifier::tlsAllowCacheKey('track.example.com')));
    }
}
