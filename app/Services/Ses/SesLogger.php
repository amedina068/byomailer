<?php

namespace App\Services\Ses;

use Aws\CommandInterface;
use Aws\ResultInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Throwable;

class SesLogger
{
    public function __construct(private int $clientId) {}

    /**
     * @return callable(CommandInterface, ?RequestInterface): PromiseInterface
     */
    public function __invoke(callable $handler): callable
    {
        return function (CommandInterface $cmd, ?RequestInterface $req = null) use ($handler): PromiseInterface {
            $operationName = $cmd->getName();

            return $handler($cmd, $req)->then(
                function (ResultInterface $result) use ($cmd, $operationName): ResultInterface {
                    $this->logSuccess($cmd, $result, $operationName);

                    return $result;
                },
                function (Throwable $reason) use ($cmd, $operationName): void {
                    $this->logFailure($cmd, $reason, $operationName);

                    throw $reason;
                }
            );
        };
    }

    private const SEND_OPERATIONS = ['SendEmail', 'SendRawEmail', 'SendTemplatedEmail'];

    private function logSuccess(CommandInterface $cmd, ResultInterface $result, string $operationName): void
    {
        try {
            if ($operationName === 'SendBulkEmail') {
                $this->logBulkEmail($cmd, $result, $operationName);

                return;
            }

            $isSend = in_array($operationName, self::SEND_OPERATIONS);

            $requestPayload = $cmd->toArray();
            unset($requestPayload['@context']);

            $recipient = $isSend
                ? ($cmd['Destination']['ToAddresses'][0] ?? $cmd['Destinations'][0] ?? $cmd['Source'] ?? null)
                : null;

            DB::table('email_logs')->insert([
                'client_id' => $this->clientId,
                'provider' => 'ses',
                'operation' => $operationName,
                'recipient' => $recipient,
                'message_id' => $result['MessageId'] ?? null,
                'status' => $isSend ? 'sent' : 'success',
                'error' => null,
                'request_payload' => json_encode($requestPayload),
                'response_payload' => json_encode($result->toArray()),
                'sns_payload' => null,
                'logged_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('SesLogger: failed to log success', [
                'operation' => $operationName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function logBulkEmail(CommandInterface $cmd, ResultInterface $result, string $operationName): void
    {
        $entries = $cmd['BulkEmailEntries'] ?? [];
        $results = $result['BulkEmailEntryResults'] ?? [];
        $now = now();
        $rows = [];

        foreach ($results as $index => $entryResult) {
            $destination = $entries[$index] ?? [];
            $recipient = $destination['Destination']['ToAddresses'][0] ?? null;
            $messageId = $entryResult['MessageId'] ?? null;
            $entryStatus = ($entryResult['Status'] ?? '') === 'SUCCESS' ? 'sent' : 'failed';
            $error = $entryResult['Error'] ?? null;

            $rows[] = [
                'client_id' => $this->clientId,
                'provider' => 'ses',
                'operation' => $operationName,
                'recipient' => $recipient,
                'message_id' => $messageId,
                'status' => $entryStatus,
                'error' => $error,
                'request_payload' => null,
                'response_payload' => json_encode($entryResult),
                'sns_payload' => null,
                'logged_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($rows)) {
            DB::table('email_logs')->insert($rows);
        }
    }

    private function logFailure(CommandInterface $cmd, Throwable $reason, string $operationName): void
    {
        try {
            $requestPayload = $cmd->toArray();
            unset($requestPayload['@context']);

            DB::table('email_logs')->insert([
                'client_id' => $this->clientId,
                'provider' => 'ses',
                'operation' => $operationName,
                'recipient' => null,
                'message_id' => null,
                'status' => 'failed',
                'error' => $reason->getMessage(),
                'request_payload' => json_encode($requestPayload),
                'response_payload' => null,
                'sns_payload' => null,
                'logged_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('SesLogger: failed to log failure', [
                'operation' => $operationName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
