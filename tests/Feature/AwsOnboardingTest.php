<?php

namespace Tests\Feature;

use App\Models\EmailCredential;
use App\Models\User;
use App\Services\AwsCredentialValidator;
use App\Services\SesTenantService;
use App\Services\SnsTopicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class AwsOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_onboarding_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('onboarding.aws'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/AwsCredentials')
            ->has('hasCredentials')
        );
    }

    public function test_guest_cannot_access_onboarding_routes(): void
    {
        $this->get(route('onboarding.aws'))->assertRedirect(route('login'));
        $this->post(route('onboarding.aws.credentials'), [])->assertRedirect(route('login'));
    }

    public function test_store_credentials_validates_credentials_before_storing(): void
    {
        $this->setSqsConfig();
        $user = User::factory()->create();

        $mockValidator = Mockery::mock(AwsCredentialValidator::class);
        $mockValidator->shouldReceive('validate')
            ->once()
            ->with('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1')
            ->andReturn([
                'valid' => true,
                'account_id' => '123456789012',
            ]);

        $mockSnsService = Mockery::mock(SnsTopicService::class);
        $mockSnsService->shouldReceive('createTopic')
            ->once()
            ->andReturn([
                'success' => true,
                'topic_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation',
            ]);
        $mockSnsService->shouldReceive('subscribeSqsEndpoint')
            ->once()
            ->andReturn([
                'success' => true,
                'subscription_arn' => 'pending confirmation',
            ]);

        $this->app->instance(AwsCredentialValidator::class, $mockValidator);
        $this->app->instance(SnsTopicService::class, $mockSnsService);
        $this->mockTenantService();

        $response = $this->actingAs($user)->postJson(route('onboarding.aws.credentials'), [
            'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('email_credentials', [
            'user_id' => $user->id,
            'type' => 'ses',
        ]);
    }

    public function test_store_credentials_returns_error_for_invalid_credentials(): void
    {
        $this->setSqsConfig();
        $user = User::factory()->create();

        $mockValidator = Mockery::mock(AwsCredentialValidator::class);
        $mockValidator->shouldReceive('validate')
            ->once()
            ->andReturn([
                'valid' => false,
                'error' => 'The security token included in the request is invalid.',
            ]);

        $this->app->instance(AwsCredentialValidator::class, $mockValidator);

        $response = $this->actingAs($user)->postJson(route('onboarding.aws.credentials'), [
            'access_key' => 'AKIAINVALIDEXAMPLE01',
            'secret_key' => 'invalid-secret-key-EXAMPLE-EXAMPLE-EXAMPLE-EXAMPLE',
            'region' => 'us-east-1',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'error' => 'The security token included in the request is invalid.',
        ]);

        $this->assertDatabaseMissing('email_credentials', ['user_id' => $user->id]);
    }

    public function test_store_credentials_returns_error_when_template_permissions_missing(): void
    {
        $this->setSqsConfig();
        $user = User::factory()->create();

        $mockValidator = Mockery::mock(AwsCredentialValidator::class);
        $mockValidator->shouldReceive('validate')
            ->once()
            ->andReturn([
                'valid' => false,
                'error' => 'SES template access denied: The IAM credentials must have permissions to manage email templates. '
                    .'Please add ses:CreateTemplate, ses:DeleteTemplate, ses:GetTemplate, and ses:ListTemplates permissions.',
            ]);

        $this->app->instance(AwsCredentialValidator::class, $mockValidator);

        $response = $this->actingAs($user)->postJson(route('onboarding.aws.credentials'), [
            'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'error' => 'SES template access denied: The IAM credentials must have permissions to manage email templates. '
                .'Please add ses:CreateTemplate, ses:DeleteTemplate, ses:GetTemplate, and ses:ListTemplates permissions.',
        ]);

        $this->assertDatabaseMissing('email_credentials', ['user_id' => $user->id]);
    }

    public function test_store_credentials_validates_input_format(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('onboarding.aws.credentials'), [
            'access_key' => 'short',
            'secret_key' => 'tiny',
            'region' => 'invalid-region',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['access_key', 'secret_key', 'region']);
    }

    public function test_store_credentials_creates_new_credential_with_sns_topic(): void
    {
        $this->setSqsConfig();
        $user = User::factory()->create();

        $mockValidator = Mockery::mock(AwsCredentialValidator::class);
        $mockValidator->shouldReceive('validate')
            ->once()
            ->with('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1')
            ->andReturn([
                'valid' => true,
                'account_id' => '123456789012',
            ]);

        $mockSnsService = Mockery::mock(SnsTopicService::class);
        $mockSnsService->shouldReceive('createTopic')
            ->once()
            ->andReturn([
                'success' => true,
                'topic_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation',
            ]);
        $mockSnsService->shouldReceive('subscribeSqsEndpoint')
            ->once()
            ->andReturn([
                'success' => true,
                'subscription_arn' => 'pending confirmation',
            ]);

        $this->app->instance(AwsCredentialValidator::class, $mockValidator);
        $this->app->instance(SnsTopicService::class, $mockSnsService);
        $this->mockTenantService();

        $response = $this->actingAs($user)->postJson(route('onboarding.aws.credentials'), [
            'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $credential = EmailCredential::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($credential);
        $this->assertSame('ses', $credential->type);
        $this->assertSame('AKIAIOSFODNN7EXAMPLE', $credential->access_key);
        $this->assertSame('us-east-1', $credential->region);
        $this->assertSame('arn:aws:sns:us-east-1:123456789012:mailautomation', $credential->sns_topic_arn);
        $this->assertSame('pending confirmation', $credential->sns_subscription_arn);
        $this->assertNotNull($credential->sns_subscription_confirmed_at);
        $this->assertSame('byomailer', $credential->tenant_name);
        $this->assertSame('123456789012', $credential->aws_account_id);
    }

    public function test_store_credentials_requires_sqs_queue_arn(): void
    {
        config(['services.sqs_ses_events.queue_arn' => null]);
        $user = User::factory()->create();

        $mockValidator = Mockery::mock(AwsCredentialValidator::class);
        $mockValidator->shouldReceive('validate')
            ->once()
            ->andReturn(['valid' => true, 'account_id' => '123456789012']);

        $mockSnsService = Mockery::mock(SnsTopicService::class);
        $mockSnsService->shouldReceive('createTopic')
            ->once()
            ->andReturn([
                'success' => true,
                'topic_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation',
            ]);
        $mockSnsService->shouldReceive('deleteTopic')
            ->once()
            ->andReturn(['success' => true]);

        $this->app->instance(AwsCredentialValidator::class, $mockValidator);
        $this->app->instance(SnsTopicService::class, $mockSnsService);

        $response = $this->actingAs($user)->postJson(route('onboarding.aws.credentials'), [
            'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'error' => 'SQS queue ARN is not configured. Set SES_EVENTS_SQS_QUEUE_ARN in your environment.',
        ]);
    }

    public function test_store_credentials_returns_error_when_sns_topic_creation_fails(): void
    {
        $this->setSqsConfig();
        $user = User::factory()->create();

        $mockValidator = Mockery::mock(AwsCredentialValidator::class);
        $mockValidator->shouldReceive('validate')
            ->once()
            ->andReturn(['valid' => true, 'account_id' => '123456789012']);

        $mockSnsService = Mockery::mock(SnsTopicService::class);
        $mockSnsService->shouldReceive('createTopic')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Access denied',
            ]);

        $this->app->instance(AwsCredentialValidator::class, $mockValidator);
        $this->app->instance(SnsTopicService::class, $mockSnsService);

        $response = $this->actingAs($user)->postJson(route('onboarding.aws.credentials'), [
            'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'error' => 'Failed to create SNS topic: Access denied',
        ]);

        $this->assertNull(EmailCredential::query()->where('user_id', $user->id)->first());
    }

    public function test_store_credentials_returns_error_when_sns_subscription_fails(): void
    {
        $this->setSqsConfig();
        $user = User::factory()->create();

        $mockValidator = Mockery::mock(AwsCredentialValidator::class);
        $mockValidator->shouldReceive('validate')
            ->once()
            ->andReturn(['valid' => true, 'account_id' => '123456789012']);

        $mockSnsService = Mockery::mock(SnsTopicService::class);
        $mockSnsService->shouldReceive('createTopic')
            ->once()
            ->andReturn([
                'success' => true,
                'topic_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation',
            ]);
        $mockSnsService->shouldReceive('subscribeSqsEndpoint')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Endpoint disabled',
            ]);
        $mockSnsService->shouldReceive('deleteTopic')
            ->once()
            ->with('arn:aws:sns:us-east-1:123456789012:mailautomation', Mockery::type('string'), Mockery::type('string'), Mockery::type('string'), null)
            ->andReturn(['success' => true]);

        $this->app->instance(AwsCredentialValidator::class, $mockValidator);
        $this->app->instance(SnsTopicService::class, $mockSnsService);

        $response = $this->actingAs($user)->postJson(route('onboarding.aws.credentials'), [
            'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'error' => 'Failed to subscribe SNS topic: Endpoint disabled',
        ]);

        $this->assertNull(EmailCredential::query()->where('user_id', $user->id)->first());
    }

    public function test_store_credentials_returns_error_when_tenant_creation_fails(): void
    {
        $this->setSqsConfig();
        $user = User::factory()->create();

        $mockValidator = Mockery::mock(AwsCredentialValidator::class);
        $mockValidator->shouldReceive('validate')
            ->once()
            ->andReturn(['valid' => true, 'account_id' => '123456789012']);

        $mockSnsService = Mockery::mock(SnsTopicService::class);
        $mockSnsService->shouldReceive('createTopic')
            ->once()
            ->andReturn([
                'success' => true,
                'topic_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation',
            ]);
        $mockSnsService->shouldReceive('subscribeSqsEndpoint')
            ->once()
            ->andReturn([
                'success' => true,
                'subscription_arn' => 'pending confirmation',
            ]);
        $mockSnsService->shouldReceive('deleteTopic')
            ->once()
            ->andReturn(['success' => true]);

        $tenantService = Mockery::mock(SesTenantService::class);
        $tenantService->shouldReceive('provisionTenant')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Access denied',
            ]);

        $this->app->instance(AwsCredentialValidator::class, $mockValidator);
        $this->app->instance(SnsTopicService::class, $mockSnsService);
        $this->app->instance(SesTenantService::class, $tenantService);

        $response = $this->actingAs($user)->postJson(route('onboarding.aws.credentials'), [
            'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'error' => 'Failed to provision SES tenant: Access denied',
        ]);

        $this->assertNull(EmailCredential::query()->where('user_id', $user->id)->first());
    }

    public function test_store_credentials_updates_existing_credential(): void
    {
        $this->setSqsConfig();
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAOLDKEYEXAMPLE001',
            'secret_key' => 'old-secret-key-1234567890123456789012345',
            'region' => 'us-east-1',
        ]);

        $mockValidator = Mockery::mock(AwsCredentialValidator::class);
        $mockValidator->shouldReceive('validate')
            ->once()
            ->andReturn(['valid' => true, 'account_id' => '123456789012']);

        $mockSnsService = Mockery::mock(SnsTopicService::class);
        $mockSnsService->shouldReceive('createTopic')
            ->once()
            ->andReturn([
                'success' => true,
                'topic_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation',
            ]);
        $mockSnsService->shouldReceive('subscribeSqsEndpoint')
            ->once()
            ->andReturn([
                'success' => true,
                'subscription_arn' => 'pending confirmation',
            ]);

        $this->app->instance(AwsCredentialValidator::class, $mockValidator);
        $this->app->instance(SnsTopicService::class, $mockSnsService);
        $this->mockTenantService();

        $response = $this->actingAs($user)->postJson(route('onboarding.aws.credentials'), [
            'access_key' => 'AKIANEWKEYEXAMPLE001',
            'secret_key' => 'new-secret-key-1234567890123456789012345',
            'region' => 'eu-west-1',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, EmailCredential::query()->where('user_id', $user->id)->count());
        $credential = EmailCredential::query()->where('user_id', $user->id)->first();
        $this->assertSame('AKIANEWKEYEXAMPLE001', $credential->access_key);
        $this->assertSame('eu-west-1', $credential->region);
        $this->assertSame('arn:aws:sns:us-east-1:123456789012:mailautomation', $credential->sns_topic_arn);
    }

    public function test_teammate_setup_updates_the_client_credential_in_place(): void
    {
        $this->setSqsConfig();
        $owner = User::factory()->create();
        $teammate = User::factory()->create(['client_id' => $owner->client_id]);
        $credential = EmailCredential::factory()->for($owner)->create([
            'aws_account_id' => '123456789012',
            'open_tracking_enabled' => false,
        ]);

        $mockValidator = Mockery::mock(AwsCredentialValidator::class);
        $mockValidator->shouldReceive('validate')
            ->once()
            ->andReturn(['valid' => true, 'account_id' => '123456789012']);

        $mockSnsService = Mockery::mock(SnsTopicService::class);
        $mockSnsService->shouldReceive('createTopic')
            ->once()
            ->andReturn(['success' => true, 'topic_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation-new']);
        $mockSnsService->shouldReceive('subscribeSqsEndpoint')
            ->once()
            ->andReturn(['success' => true, 'subscription_arn' => 'pending confirmation']);

        $this->app->instance(AwsCredentialValidator::class, $mockValidator);
        $this->app->instance(SnsTopicService::class, $mockSnsService);
        $this->mockTenantService();

        $this->actingAs($teammate)->postJson(route('onboarding.aws.credentials'), [
            'access_key' => 'AKIANEWKEYEXAMPLE001',
            'secret_key' => 'new-secret-key-1234567890123456789012345',
            'region' => 'us-east-1',
        ])->assertOk()->assertJson(['success' => true, 'credential_id' => $credential->id]);

        $saved = EmailCredential::query()->sole();

        $this->assertSame($owner->id, $saved->user_id);
        $this->assertSame('AKIANEWKEYEXAMPLE001', $saved->access_key);
        $this->assertSame('byomailer', $saved->tenant_name);
        $this->assertSame('arn:aws:sns:us-east-1:123456789012:mailautomation-new', $saved->sns_topic_arn);
        $this->assertFalse($saved->open_tracking_enabled);
    }

    private function mockTenantService(): void
    {
        $tenantService = Mockery::mock(SesTenantService::class);
        $tenantService->shouldReceive('provisionTenant')->andReturn(['success' => true]);
        $this->app->instance(SesTenantService::class, $tenantService);
    }

    private function setSqsConfig(): void
    {
        config(['services.sqs_ses_events.queue_arn' => 'arn:aws:sqs:us-east-1:999999999999:ses-events']);
    }
}
