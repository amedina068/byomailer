<?php

namespace Tests\Feature;

use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\SesConfigurationSetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EmailTrackingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_tracking_settings(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAEXAMPLEUSER12345',
            'secret_key' => 'example-secret-key-1234567890123456789012345',
            'region' => 'us-east-1',
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:test-topic',
        ]);

        $mockConfigService = Mockery::mock(SesConfigurationSetService::class);
        $mockConfigService->shouldReceive('getEventTypesForTracking')
            ->once()
            ->with(false, true)
            ->andReturn(['SEND', 'DELIVERY', 'BOUNCE', 'COMPLAINT', 'REJECT', 'RENDERING_FAILURE', 'CLICK']);
        $mockConfigService->shouldReceive('updateSnsEventDestination')
            ->once()
            ->andReturn(['success' => true]);

        $this->app->instance(SesConfigurationSetService::class, $mockConfigService);

        $response = $this->actingAs($user)->putJson(route('identities.update-tracking', $identity), [
            'open_tracking_enabled' => false,
            'click_tracking_enabled' => true,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $identity->refresh();
        $this->assertFalse($identity->open_tracking_enabled);
        $this->assertTrue($identity->click_tracking_enabled);
    }

    public function test_tracking_update_returns_error_when_no_credentials(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create();

        $response = $this->actingAs($user)->putJson(route('identities.update-tracking', $identity), [
            'open_tracking_enabled' => true,
            'click_tracking_enabled' => true,
        ]);

        $response->assertStatus(404)->assertJson(['error' => 'No AWS credentials found. Add your AWS credentials in Settings.']);
    }

    public function test_tracking_update_returns_error_when_event_destination_update_fails(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAEXAMPLEUSER12345',
            'secret_key' => 'example-secret-key-1234567890123456789012345',
            'region' => 'us-east-1',
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:test-topic',
        ]);

        $mockConfigService = Mockery::mock(SesConfigurationSetService::class);
        $mockConfigService->shouldReceive('getEventTypesForTracking')
            ->once()
            ->andReturn(['SEND', 'DELIVERY']);
        $mockConfigService->shouldReceive('updateSnsEventDestination')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Destination not found',
            ]);

        $this->app->instance(SesConfigurationSetService::class, $mockConfigService);

        $response = $this->actingAs($user)->putJson(route('identities.update-tracking', $identity), [
            'open_tracking_enabled' => false,
            'click_tracking_enabled' => false,
        ]);

        $response->assertStatus(422)->assertJson([
            'error' => 'Failed to update event destination: Destination not found',
        ]);

        $identity->refresh();
        $this->assertTrue($identity->open_tracking_enabled);
        $this->assertTrue($identity->click_tracking_enabled);
    }

    public function test_tracking_update_validates_input(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAEXAMPLEUSER12345',
            'secret_key' => 'example-secret-key-1234567890123456789012345',
            'region' => 'us-east-1',
        ]);

        $response = $this->actingAs($user)->putJson(route('identities.update-tracking', $identity), []);

        $response->assertStatus(422)->assertJsonValidationErrors([
            'open_tracking_enabled',
            'click_tracking_enabled',
        ]);
    }

    public function test_guest_cannot_update_tracking_settings(): void
    {
        $this->putJson(route('identities.update-tracking', 999), [
            'open_tracking_enabled' => true,
            'click_tracking_enabled' => true,
        ])->assertUnauthorized();
    }

    public function test_user_cannot_update_tracking_for_other_clients_identity(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:test-topic',
        ]);

        $otherUser = User::factory()->create();
        $otherIdentity = EmailIdentity::factory()->for($otherUser)->withConfigurationSet()->create();

        $response = $this->actingAs($user)->putJson(
            route('identities.update-tracking', $otherIdentity),
            [
                'open_tracking_enabled' => false,
                'click_tracking_enabled' => false,
            ]
        );

        $response->assertNotFound();
    }

    public function test_get_event_types_for_tracking_includes_open_when_enabled(): void
    {
        $service = new SesConfigurationSetService;

        $types = $service->getEventTypesForTracking(true, false);

        $this->assertContains('OPEN', $types);
        $this->assertNotContains('CLICK', $types);
        $this->assertContains('SEND', $types);
        $this->assertContains('DELIVERY', $types);
        $this->assertContains('BOUNCE', $types);
    }

    public function test_get_event_types_for_tracking_includes_click_when_enabled(): void
    {
        $service = new SesConfigurationSetService;

        $types = $service->getEventTypesForTracking(false, true);

        $this->assertNotContains('OPEN', $types);
        $this->assertContains('CLICK', $types);
    }

    public function test_get_event_types_for_tracking_includes_both_when_enabled(): void
    {
        $service = new SesConfigurationSetService;

        $types = $service->getEventTypesForTracking(true, true);

        $this->assertContains('OPEN', $types);
        $this->assertContains('CLICK', $types);
    }

    public function test_get_event_types_for_tracking_excludes_both_when_disabled(): void
    {
        $service = new SesConfigurationSetService;

        $types = $service->getEventTypesForTracking(false, false);

        $this->assertNotContains('OPEN', $types);
        $this->assertNotContains('CLICK', $types);
    }
}
