<?php

namespace Tests\Feature;

use App\Console\Commands\PollSesEventsCommand;
use App\Jobs\ProcessSesEventJob;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class PollSesEventsCommandTest extends TestCase
{
    public function test_fails_when_config_is_missing(): void
    {
        config([
            'services.sqs_ses_events.queue_url' => null,
            'services.sqs_ses_events.key' => null,
            'services.sqs_ses_events.secret' => null,
        ]);

        $this->artisan('ses-events:poll', ['--max-polls' => 1])
            ->expectsOutputToContain('SQS SES events configuration is incomplete')
            ->assertExitCode(1);
    }

    public function test_dispatches_job_per_message_and_deletes_from_sqs(): void
    {
        Queue::fake();

        $this->configureSqsConfig();

        $snsPayload = json_encode([
            'Type' => 'Notification',
            'Message' => json_encode(['eventType' => 'DELIVERY', 'mail' => ['tags' => ['contact_mail_id' => [1]]]]),
        ]);

        $deletedHandles = [];

        $mockClient = Mockery::mock(SqsClient::class);
        $mockClient->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([
                'Messages' => [
                    [
                        'MessageId' => 'msg-1',
                        'ReceiptHandle' => 'handle-1',
                        'Body' => $snsPayload,
                    ],
                    [
                        'MessageId' => 'msg-2',
                        'ReceiptHandle' => 'handle-2',
                        'Body' => $snsPayload,
                    ],
                ],
            ]));

        $mockClient->shouldReceive('deleteMessage')
            ->twice()
            ->andReturnUsing(function ($args) use (&$deletedHandles) {
                $deletedHandles[] = $args['ReceiptHandle'];

                return new Result([]);
            });

        $exitCode = $this->runCommandWithMock($mockClient, ['--max-polls' => 1]);

        $this->assertSame(0, $exitCode);
        Queue::assertPushed(ProcessSesEventJob::class, 2);
        $this->assertContains('handle-1', $deletedHandles);
        $this->assertContains('handle-2', $deletedHandles);
    }

    public function test_skips_invalid_message_body_and_deletes(): void
    {
        Queue::fake();

        $this->configureSqsConfig();

        $mockClient = Mockery::mock(SqsClient::class);
        $mockClient->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([
                'Messages' => [
                    [
                        'MessageId' => 'msg-bad',
                        'ReceiptHandle' => 'handle-bad',
                        'Body' => 'not-json',
                    ],
                ],
            ]));

        $mockClient->shouldNotReceive('deleteMessage');

        $exitCode = $this->runCommandWithMock($mockClient, ['--max-polls' => 1]);

        $this->assertSame(0, $exitCode);
        Queue::assertNotPushed(ProcessSesEventJob::class);
    }

    public function test_handles_empty_poll_gracefully(): void
    {
        $this->configureSqsConfig();

        $mockClient = Mockery::mock(SqsClient::class);
        $mockClient->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result(['Messages' => []]));

        $exitCode = $this->runCommandWithMock($mockClient, ['--max-polls' => 1, '--sleep' => 0]);

        $this->assertSame(0, $exitCode);
    }

    public function test_exits_gracefully_when_queue_restart_is_signalled(): void
    {
        $this->configureSqsConfig();

        Cache::forget('illuminate:queue:restart');

        $mockClient = Mockery::mock(SqsClient::class);
        $mockClient->shouldReceive('receiveMessage')
            ->once()
            ->andReturnUsing(function () {
                Cache::put('illuminate:queue:restart', 1234567890);

                return new Result(['Messages' => []]);
            });

        $exitCode = $this->runCommandWithMock($mockClient, ['--sleep' => 0]);

        $this->assertSame(0, $exitCode);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function runCommandWithMock(SqsClient $mockClient, array $options = []): int
    {
        $command = new PollSesEventsCommand;
        $command->setClientFactory(fn ($config) => $mockClient);
        $command->setLaravel($this->app);

        $parameters = $options;

        return $command->run(
            new ArrayInput($parameters, $command->getDefinition()),
            new BufferedOutput
        );
    }

    private function configureSqsConfig(): void
    {
        config([
            'services.sqs_ses_events.key' => 'test-key',
            'services.sqs_ses_events.secret' => 'test-secret',
            'services.sqs_ses_events.region' => 'us-east-1',
            'services.sqs_ses_events.queue_url' => 'https://sqs.us-east-1.amazonaws.com/999999999999/ses-events',
        ]);
    }
}
