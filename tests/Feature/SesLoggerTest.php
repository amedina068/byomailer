<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Services\Ses\SesLogger;
use Aws\Command;
use Aws\Result;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SesLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_a_successful_ses_call(): void
    {
        $client = Client::factory()->create();
        $logger = new SesLogger($client->id);

        $handler = function () {
            return new FulfilledPromise(new Result([
                'MessageId' => 'test-msg-id',
            ]));
        };

        $middleware = $logger($handler);
        $cmd = new Command('SendEmail', ['Destination' => ['ToAddresses' => ['test@example.com']]]);
        $middleware($cmd)->wait();

        $this->assertDatabaseHas('email_logs', [
            'client_id' => $client->id,
            'provider' => 'ses',
            'operation' => 'SendEmail',
            'status' => 'sent',
        ]);
    }

    public function test_it_logs_a_failed_ses_call(): void
    {
        $client = Client::factory()->create();
        $logger = new SesLogger($client->id);

        $handler = function () {
            return new RejectedPromise(new RuntimeException('Access denied'));
        };

        $middleware = $logger($handler);
        $cmd = new Command('SendEmail', ['Destination' => ['ToAddresses' => ['test@example.com']]]);

        try {
            $middleware($cmd)->wait();
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseHas('email_logs', [
            'client_id' => $client->id,
            'operation' => 'SendEmail',
            'status' => 'failed',
            'error' => 'Access denied',
        ]);
    }

    public function test_it_logs_per_recipient_for_send_bulk_email(): void
    {
        $client = Client::factory()->create();
        $logger = new SesLogger($client->id);

        $handler = function () {
            return new FulfilledPromise(new Result([
                'BulkEmailEntryResults' => [
                    ['Status' => 'SUCCESS', 'MessageId' => 'msg-1'],
                    ['Status' => 'FAILED', 'Error' => 'Account suspended', 'MessageId' => null],
                    ['Status' => 'SUCCESS', 'MessageId' => 'msg-3'],
                ],
            ]));
        };

        $middleware = $logger($handler);
        $cmd = new Command('SendBulkEmail', [
            'BulkEmailEntries' => [
                ['Destination' => ['ToAddresses' => ['alice@example.com']]],
                ['Destination' => ['ToAddresses' => ['bob@example.com']]],
                ['Destination' => ['ToAddresses' => ['carol@example.com']]],
            ],
        ]);
        $middleware($cmd)->wait();

        $this->assertDatabaseCount('email_logs', 3);

        $this->assertDatabaseHas('email_logs', [
            'client_id' => $client->id,
            'operation' => 'SendBulkEmail',
            'recipient' => 'alice@example.com',
            'message_id' => 'msg-1',
            'status' => 'sent',
        ]);

        $this->assertDatabaseHas('email_logs', [
            'client_id' => $client->id,
            'operation' => 'SendBulkEmail',
            'recipient' => 'bob@example.com',
            'status' => 'failed',
            'error' => 'Account suspended',
        ]);

        $this->assertDatabaseHas('email_logs', [
            'recipient' => 'carol@example.com',
            'message_id' => 'msg-3',
            'status' => 'sent',
        ]);
    }

    public function test_logging_failure_does_not_break_ses_call(): void
    {
        // Use client_id 0 which doesn't exist — the FK constraint will fail the insert
        // but the SES call should still succeed
        $logger = new SesLogger(0);

        $handler = function () {
            return new FulfilledPromise(new Result(['MessageId' => 'test']));
        };

        $middleware = $logger($handler);
        $cmd = new Command('SendEmail', []);
        $result = $middleware($cmd)->wait();

        $this->assertInstanceOf(Result::class, $result);
        $this->assertDatabaseCount('email_logs', 0);
    }

    public function test_failure_is_rethrown_after_logging(): void
    {
        $client = Client::factory()->create();
        $logger = new SesLogger($client->id);

        $handler = function () {
            return new RejectedPromise(new RuntimeException('Network error'));
        };

        $middleware = $logger($handler);
        $cmd = new Command('SendEmail', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Network error');
        $middleware($cmd)->wait();
    }
}
