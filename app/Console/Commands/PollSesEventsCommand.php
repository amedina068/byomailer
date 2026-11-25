<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSesEventJob;
use Aws\Sqs\SqsClient;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PollSesEventsCommand extends Command
{
    protected $signature = 'ses-events:poll
        {--max-messages=10 : Maximum messages per poll (1-10)}
        {--wait-time=20 : Long poll wait time in seconds (0-20)}
        {--visibility-timeout=60 : Message visibility timeout in seconds}
        {--sleep=1 : Seconds to sleep between empty polls}
        {--memory-limit=256 : Memory limit in MB before graceful exit}
        {--max-polls=0 : Maximum number of polls before exiting (0 = unlimited)}';

    protected $description = 'Poll SQS queue for SES events and dispatch jobs';

    private bool $shouldStop = false;

    private ?Closure $clientFactory = null;

    private int $errorBackoff = 5;

    private const MAX_ERROR_BACKOFF = 60;

    /**
     * @param  Closure(array<string, mixed>): SqsClient  $factory
     */
    public function setClientFactory(Closure $factory): void
    {
        $this->clientFactory = $factory;
    }

    public function handle(): int
    {
        $config = config('services.sqs_ses_events');

        if (! is_array($config) || ! ($config['queue_url'] ?? null) || ! ($config['key'] ?? null) || ! ($config['secret'] ?? null)) {
            $this->error('SQS SES events configuration is incomplete. Check PLATFORM_AWS_* and SES_EVENTS_SQS_QUEUE_URL env vars.');

            return self::FAILURE;
        }

        $client = $this->createClient($config);

        $this->registerSignalHandlers();

        $maxMessages = (int) $this->option('max-messages');
        $waitTime = (int) $this->option('wait-time');
        $visibilityTimeout = (int) $this->option('visibility-timeout');
        $sleepSeconds = (int) $this->option('sleep');
        $memoryLimit = (int) $this->option('memory-limit') * 1024 * 1024;
        $maxPolls = (int) $this->option('max-polls');
        $pollCount = 0;
        $lastRestart = $this->getLastQueueRestart();

        $this->log('Polling SQS for SES events...');

        while (! $this->shouldStop) {
            if ($this->queueShouldRestart($lastRestart)) {
                $this->log('Queue restart signalled, exiting gracefully.');

                break;
            }

            try {
                $result = $client->receiveMessage([
                    'QueueUrl' => $config['queue_url'],
                    'MaxNumberOfMessages' => $maxMessages,
                    'WaitTimeSeconds' => $waitTime,
                    'VisibilityTimeout' => $visibilityTimeout,
                ]);

                $this->errorBackoff = 5;

                $messages = $result->get('Messages') ?? [];
                $pollCount++;

                if (empty($messages)) {
                    if ($maxPolls > 0 && $pollCount >= $maxPolls) {
                        break;
                    }

                    sleep($sleepSeconds);

                    continue;
                }

                foreach ($messages as $message) {
                    if ($this->shouldStop) {
                        break;
                    }

                    $this->processMessage($client, $config['queue_url'], $message);
                }

                if ($maxPolls > 0 && $pollCount >= $maxPolls) {
                    break;
                }

                if (memory_get_usage(true) > $memoryLimit) {
                    $this->log('Memory limit approaching, exiting gracefully.');

                    return self::SUCCESS;
                }
            } catch (Throwable $e) {
                Log::channel('ses-events')->error('PollSesEventsCommand: SQS error', [
                    'error' => $e->getMessage(),
                    'backoff' => $this->errorBackoff,
                ]);

                $this->log("SQS error: {$e->getMessage()} (retrying in {$this->errorBackoff}s)", 'error');

                sleep($this->errorBackoff);

                $this->errorBackoff = min($this->errorBackoff * 2, self::MAX_ERROR_BACKOFF);
            }
        }

        $this->log('Shutting down.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function processMessage(SqsClient $client, string $queueUrl, array $message): void
    {
        $rawBody = $message['Body'] ?? '';
        $body = json_decode($rawBody, true);

        if (! is_array($body)) {
            $this->log("Invalid message body (message: {$message['MessageId']}): ".mb_substr($rawBody, 0, 200), 'warn');

            Log::channel('ses-events')->warning('PollSesEventsCommand: invalid message body, leaving for DLQ', [
                'message_id' => $message['MessageId'] ?? null,
                'raw_body' => mb_substr($rawBody, 0, 500),
                'json_error' => json_last_error_msg(),
            ]);

            return;
        }

        $type = $body['Type'] ?? 'direct';
        $this->log("Dispatching job (message: {$message['MessageId']}, type: {$type})");

        Log::channel('ses-events')->info('PollSesEventsCommand: dispatching job', [
            'message_id' => $message['MessageId'] ?? null,
            'type' => $type,
        ]);

        try {
            ProcessSesEventJob::dispatch($body);

            $this->deleteMessage($client, $queueUrl, $message);
        } catch (Throwable $e) {
            Log::channel('ses-events')->error('PollSesEventsCommand: failed to dispatch job', [
                'message_id' => $message['MessageId'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function deleteMessage(SqsClient $client, string $queueUrl, array $message): void
    {
        try {
            $client->deleteMessage([
                'QueueUrl' => $queueUrl,
                'ReceiptHandle' => $message['ReceiptHandle'],
            ]);
        } catch (Throwable $e) {
            Log::channel('ses-events')->error('PollSesEventsCommand: failed to delete message', [
                'message_id' => $message['MessageId'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function log(string $message, string $level = 'info'): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');

        match ($level) {
            'warn' => $this->warn("[{$timestamp}] {$message}"),
            'error' => $this->error("[{$timestamp}] {$message}"),
            default => $this->line("[{$timestamp}] {$message}"),
        };
    }

    private function getLastQueueRestart(): mixed
    {
        return Cache::get('illuminate:queue:restart');
    }

    private function queueShouldRestart(mixed $lastRestart): bool
    {
        return $this->getLastQueueRestart() !== $lastRestart;
    }

    private function registerSignalHandlers(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createClient(array $config): SqsClient
    {
        $clientConfig = [
            'version' => '2012-11-05',
            'region' => $config['region'],
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ],
        ];

        if ($this->clientFactory) {
            return ($this->clientFactory)($clientConfig);
        }

        return new SqsClient($clientConfig);
    }
}
