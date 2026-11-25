<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSesEventJob;
use App\Models\EmailCredential;
use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class SesWebhookController extends Controller
{
    /**
     * Envelope keys that the SNS signature covers or that the validator reads.
     * Each one must be a string when it is present.
     */
    private const array SIGNATURE_KEYS = [
        'Type',
        'Message',
        'MessageId',
        'Subject',
        'SubscribeURL',
        'SubscribeUrl',
        'Timestamp',
        'Token',
        'TopicArn',
        'Signature',
        'SignatureVersion',
        'SigningCertURL',
        'SigningCertUrl',
    ];

    /**
     * A signed message is accepted one time, and only while it is newer than this.
     * SNS retries a failed HTTP delivery for much less time.
     */
    private const int REPLAY_WINDOW_HOURS = 24;

    public function __construct(private MessageValidator $messageValidator) {}

    /**
     * Accept only SNS messages that AWS signed and that come from a topic
     * that onboarding created for a tenant.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! $this->hasStringSignatureKeys($payload)) {
            return $this->reject($request, 'The body is not an SNS JSON envelope.');
        }

        try {
            $message = new Message($payload);
        } catch (InvalidArgumentException $exception) {
            return $this->reject($request, $exception->getMessage());
        }

        if (! $this->isKnownTopic($message['TopicArn'])) {
            return $this->reject($request, 'The topic is not a known tenant topic.', $message);
        }

        try {
            $this->messageValidator->validate($message);
        } catch (InvalidSnsMessageException $exception) {
            return $this->reject($request, $exception->getMessage(), $message);
        }

        if ($this->isOutsideReplayWindow($message['Timestamp'])) {
            return $this->reject($request, 'The message is older than the replay window.', $message);
        }

        $replayKey = 'ses-webhook:sns-message:'.$message['MessageId'];

        if (! Cache::add($replayKey, true, now()->addHours(self::REPLAY_WINDOW_HOURS))) {
            return response()->json(['queued' => false]);
        }

        try {
            ProcessSesEventJob::dispatch($message->toArray());
        } catch (Throwable $exception) {
            Cache::forget($replayKey);

            throw $exception;
        }

        return response()->json(['queued' => true]);
    }

    private function isOutsideReplayWindow(string $timestamp): bool
    {
        try {
            return CarbonImmutable::parse($timestamp)->lt(now()->subHours(self::REPLAY_WINDOW_HOURS));
        } catch (InvalidFormatException) {
            return true;
        }
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function hasStringSignatureKeys(array $payload): bool
    {
        foreach (self::SIGNATURE_KEYS as $key) {
            if (array_key_exists($key, $payload) && ! is_string($payload[$key])) {
                return false;
            }
        }

        return true;
    }

    private function isKnownTopic(string $topicArn): bool
    {
        return EmailCredential::withoutGlobalScopes()
            ->where('sns_topic_arn', $topicArn)
            ->exists();
    }

    private function reject(Request $request, string $reason, ?Message $message = null): JsonResponse
    {
        Log::warning('SesWebhookController: rejected SNS message', [
            'reason' => $reason,
            'message_id' => $message?->offsetGet('MessageId'),
            'topic_arn' => $message?->offsetGet('TopicArn'),
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Forbidden.'], 403);
    }
}
