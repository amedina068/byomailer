<?php

namespace Tests\Feature;

use App\Models\EmailCredential;
use App\Models\User;
use App\Services\SnsTopicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MigrateSnsToSqsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fails_when_queue_arn_not_configured(): void
    {
        config(['services.sqs_ses_events.queue_arn' => null]);

        $this->artisan('ses-events:migrate-to-sqs')
            ->expectsOutputToContain('SES_EVENTS_SQS_QUEUE_ARN is not configured')
            ->assertExitCode(1);
    }

    public function test_succeeds_with_no_credentials(): void
    {
        config(['services.sqs_ses_events.queue_arn' => 'arn:aws:sqs:us-east-1:999999999999:ses-events']);

        $this->artisan('ses-events:migrate-to-sqs')
            ->expectsOutputToContain('No credentials with SNS topics found')
            ->assertExitCode(0);
    }

    public function test_subscribes_sqs_and_unsubscribes_https(): void
    {
        config(['services.sqs_ses_events.queue_arn' => 'arn:aws:sqs:us-east-1:999999999999:ses-events']);

        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create([
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation',
            'sns_subscription_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation:old-sub',
            'sns_subscription_confirmed_at' => now(),
        ]);

        $mockSnsService = Mockery::mock(SnsTopicService::class);
        $mockSnsService->shouldReceive('subscribeSqsEndpoint')
            ->once()
            ->with(
                'arn:aws:sns:us-east-1:123456789012:mailautomation',
                'arn:aws:sqs:us-east-1:999999999999:ses-events',
                Mockery::type('string'),
                Mockery::type('string'),
                Mockery::type('string')
            )
            ->andReturn([
                'success' => true,
                'subscription_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation:new-sqs-sub',
            ]);

        $mockSnsService->shouldReceive('unsubscribe')
            ->once()
            ->with(
                'arn:aws:sns:us-east-1:123456789012:mailautomation:old-sub',
                Mockery::type('string'),
                Mockery::type('string'),
                Mockery::type('string')
            )
            ->andReturn(['success' => true]);

        $this->app->instance(SnsTopicService::class, $mockSnsService);

        $this->artisan('ses-events:migrate-to-sqs')
            ->expectsOutputToContain('Migrated successfully')
            ->assertExitCode(0);

        $credential->refresh();
        $this->assertSame('arn:aws:sns:us-east-1:123456789012:mailautomation:new-sqs-sub', $credential->sns_subscription_arn);
        $this->assertNotNull($credential->sns_subscription_confirmed_at);
    }

    public function test_dry_run_makes_no_changes(): void
    {
        config(['services.sqs_ses_events.queue_arn' => 'arn:aws:sqs:us-east-1:999999999999:ses-events']);

        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create([
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation',
            'sns_subscription_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation:old-sub',
            'sns_subscription_confirmed_at' => null,
        ]);

        $mockSnsService = Mockery::mock(SnsTopicService::class);
        $mockSnsService->shouldNotReceive('subscribeSqsEndpoint');
        $mockSnsService->shouldNotReceive('unsubscribe');

        $this->app->instance(SnsTopicService::class, $mockSnsService);

        $this->artisan('ses-events:migrate-to-sqs', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);

        $credential->refresh();
        $this->assertSame('arn:aws:sns:us-east-1:123456789012:mailautomation:old-sub', $credential->sns_subscription_arn);
        $this->assertNull($credential->sns_subscription_confirmed_at);
    }

    public function test_the_user_option_selects_the_credential_of_the_client_of_the_user(): void
    {
        config(['services.sqs_ses_events.queue_arn' => 'arn:aws:sqs:us-east-1:999999999999:ses-events']);

        $owner = User::factory()->create();
        $teammate = User::factory()->for($owner->client)->create();
        $credential = EmailCredential::factory()->for($owner)->create([
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:team-topic',
            'sns_subscription_arn' => null,
        ]);
        EmailCredential::factory()->create([
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:other-topic',
            'sns_subscription_arn' => null,
        ]);

        $this->artisan('ses-events:migrate-to-sqs', ['--dry-run' => true, '--user-id' => $teammate->id])
            ->expectsOutputToContain("Credential {$credential->id} (user {$owner->id}): Would subscribe SQS to arn:aws:sns:us-east-1:123456789012:team-topic")
            ->doesntExpectOutputToContain('other-topic')
            ->assertExitCode(0);
    }

    public function test_handles_subscribe_failure_gracefully(): void
    {
        config(['services.sqs_ses_events.queue_arn' => 'arn:aws:sqs:us-east-1:999999999999:ses-events']);

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation',
            'sns_subscription_arn' => null,
        ]);

        $mockSnsService = Mockery::mock(SnsTopicService::class);
        $mockSnsService->shouldReceive('subscribeSqsEndpoint')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Access denied',
            ]);

        $this->app->instance(SnsTopicService::class, $mockSnsService);

        $this->artisan('ses-events:migrate-to-sqs')
            ->expectsOutputToContain('Failed to subscribe SQS')
            ->assertExitCode(1);
    }

    public function test_skips_unsubscribe_for_pending_subscription(): void
    {
        config(['services.sqs_ses_events.queue_arn' => 'arn:aws:sqs:us-east-1:999999999999:ses-events']);

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation',
            'sns_subscription_arn' => 'pending confirmation',
        ]);

        $mockSnsService = Mockery::mock(SnsTopicService::class);
        $mockSnsService->shouldReceive('subscribeSqsEndpoint')
            ->once()
            ->andReturn([
                'success' => true,
                'subscription_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation:new-sqs-sub',
            ]);
        $mockSnsService->shouldNotReceive('unsubscribe');

        $this->app->instance(SnsTopicService::class, $mockSnsService);

        $this->artisan('ses-events:migrate-to-sqs')
            ->assertExitCode(0);
    }
}
