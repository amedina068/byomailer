<?php

namespace App\Jobs;

use App\Enums\SesEventType;
use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\EmailCredential;
use App\Services\GeoIpService;
use App\Services\SequenceStepScheduler;
use App\Support\NamedLink;
use App\Support\Tenant;
use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class ProcessSesEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const string BOUNCE_TYPE_PERMANENT = 'permanent';

    /**
     * Soft bounces in a row that suppress a contact. A delivery resets the count.
     */
    public const int SOFT_BOUNCE_LIMIT = 3;

    /**
     * Forward-only progress states. SES delivers events out of order, so
     * never regress (e.g. a late SEND must not clobber a prior DELIVERY).
     */
    private const array PROGRESS_RANK = [
        'pending' => 0,
        'sent' => 1,
        'delivered' => 2,
        'opened' => 3,
    ];

    /**
     * Terminal states — once set, progress events cannot overwrite them.
     * Terminal events always win over the current status.
     */
    private const array TERMINAL_STATUSES = [
        'bounced',
        'complained',
        'rejected',
        'failed',
        'unsubscribed',
    ];

    private SequenceStepScheduler $sequenceStepScheduler;

    private GeoIpService $geoIpService;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload)
    {
        $this->onQueue('ses-events');
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessSesEventJob failed', [
            'error' => $exception?->getMessage(),
            'payload_type' => $this->payload['Type'] ?? 'direct',
        ]);
    }

    public function handle(SequenceStepScheduler $sequenceStepScheduler, GeoIpService $geoIpService): void
    {
        $this->sequenceStepScheduler = $sequenceStepScheduler;
        $this->geoIpService = $geoIpService;

        if (! isset($this->payload['Type'])) {
            Log::warning('ProcessSesEventJob: refused a payload with no SNS envelope');

            return;
        }

        $this->handleSnsPayload($this->payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleSnsPayload(array $payload): void
    {
        $type = Str::lower((string) ($payload['Type'] ?? ''));

        if ($type === 'subscriptionconfirmation') {
            $this->confirmSubscription($payload);

            return;
        }

        if ($type === 'notification') {
            $clientIds = $this->topicClientIds($payload['TopicArn'] ?? null);

            if ($clientIds === []) {
                Log::warning('ProcessSesEventJob: refused a notification from a topic that no tenant owns', [
                    'topic_arn' => $payload['TopicArn'] ?? null,
                ]);

                return;
            }

            $events = $this->extractEventsFromSns($payload);

            if (empty($events)) {
                Log::warning('ProcessSesEventJob: SNS notification contained no events', [
                    'topic_arn' => $payload['TopicArn'] ?? null,
                ]);
            }

            $this->processEvents($events, $clientIds);

            return;
        }

        Log::warning('ProcessSesEventJob: unhandled SNS payload type', ['type' => $type]);
    }

    /**
     * When the SQS queue is in another AWS account than the topic, AWS puts a
     * confirmation message in the queue, and only the queue side can confirm.
     * Open the SubscribeURL only for a topic of a tenant and a message that AWS
     * signed, so that a forged message cannot make the worker call another host.
     *
     * @param  array<string, mixed>  $payload
     */
    private function confirmSubscription(array $payload): void
    {
        if ($this->topicClientIds($payload['TopicArn'] ?? null) === []) {
            Log::warning('ProcessSesEventJob: refused a subscription confirmation from a topic that no tenant owns', [
                'topic_arn' => $payload['TopicArn'] ?? null,
            ]);

            return;
        }

        try {
            app(MessageValidator::class)->validate(new Message($payload));
        } catch (InvalidArgumentException|InvalidSnsMessageException $exception) {
            Log::warning('ProcessSesEventJob: refused an unsigned subscription confirmation', [
                'topic_arn' => $payload['TopicArn'],
                'reason' => $exception->getMessage(),
            ]);

            return;
        }

        Http::timeout(10)->get($payload['SubscribeURL'])->throw();

        Log::info('ProcessSesEventJob: confirmed the SNS subscription', ['topic_arn' => $payload['TopicArn']]);
    }

    /**
     * The clients that own the SNS topic. A tenant controls its own topic, so
     * an event from it can only change the mails of these clients.
     *
     * @return array<int, int>
     */
    private function topicClientIds(mixed $topicArn): array
    {
        if (! is_string($topicArn) || $topicArn === '') {
            return [];
        }

        return EmailCredential::withoutGlobalScopes()
            ->where('sns_topic_arn', $topicArn)
            ->whereNotNull('client_id')
            ->pluck('client_id')
            ->map(fn (mixed $clientId): int => (int) $clientId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Each event runs in its own transaction, and a failed event does not stop
     * the other events. The poll command deletes the SQS message after the
     * dispatch, so the job rethrows the first failure and the queue retries the
     * message. The retry applies the other events again, so each write must be
     * safe to apply two times. The job reports the later failures.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<int, int>  $clientIds
     */
    private function processEvents(array $events, array $clientIds): void
    {
        $firstFailure = null;

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            try {
                $this->processEvent($event, $clientIds);
            } catch (Throwable $exception) {
                if ($firstFailure === null) {
                    $firstFailure = $exception;
                } else {
                    report($exception);
                }
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<int, int>  $clientIds
     */
    private function processEvent(array $event, array $clientIds): void
    {
        $eventName = (string) data_get($event, 'eventType', '');
        $type = SesEventType::fromEventName($eventName);

        if ($type === null) {
            Log::warning('ProcessSesEventJob: unknown SES event type', [
                'event_type' => $eventName,
                'message_id' => data_get($event, 'mail.messageId'),
            ]);

            return;
        }

        $contactMail = $this->findContactMail($event, $clientIds);

        if (! $contactMail) {
            return;
        }

        Tenant::run($contactMail->client_id, fn () => DB::transaction(fn () => $this->applyEvent($contactMail, $type, $event)));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function applyEvent(ContactMail $contactMail, SesEventType $type, array $event): void
    {
        $timestamp = $this->eventTimestamp($event);

        $updates = match ($type) {
            SesEventType::Send => [
                'status' => 'sent',
                'sent_at' => $timestamp,
            ],
            SesEventType::Delivery => $this->deliveryUpdates($contactMail, $timestamp),
            SesEventType::Open => $this->openUpdates($contactMail, $event, $timestamp),
            SesEventType::Click => $this->clickUpdates($contactMail, $event, $timestamp),
            SesEventType::Bounce => $this->bounceUpdates($contactMail, $event, $timestamp),
            SesEventType::Complaint => $this->complaintUpdates($contactMail, $event, $timestamp),
            SesEventType::Reject => [
                'rejected_at' => $timestamp,
                'status' => 'rejected',
            ],
            SesEventType::RenderingFailure => [
                'status' => 'failed',
                'failure_reason' => data_get($event, 'failure.errorMessage', 'Template rendering failed'),
            ],
            SesEventType::DeliveryDelay => null,
        };

        if ($updates === null) {
            return;
        }

        $updates['status'] = $this->resolveStatus((string) $contactMail->status, $updates['status']);

        $contactMail->forceFill($updates)->save();

        $this->updateEmailLog($event, $updates['status'], $contactMail->client_id);

        if ($type === SesEventType::Delivery) {
            $this->sequenceStepScheduler->scheduleNextStep($contactMail);
        }
    }

    /**
     * @return array{status: string, delivered_at: CarbonImmutable, sent_at: mixed}
     */
    private function deliveryUpdates(ContactMail $contactMail, CarbonImmutable $timestamp): array
    {
        $this->resetSoftBounceCount($contactMail);

        return [
            'delivered_at' => $timestamp,
            'status' => 'delivered',
            'sent_at' => $contactMail->sent_at ?: $timestamp,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{status: string, opened_at: mixed, sent_at: mixed, delivered_at: mixed}
     */
    private function openUpdates(ContactMail $contactMail, array $event, CarbonImmutable $timestamp): array
    {
        $updates = [
            'status' => 'opened',
            'opened_at' => $contactMail->opened_at ?: $timestamp,
            'sent_at' => $contactMail->sent_at ?: $timestamp,
            'delivered_at' => $contactMail->delivered_at ?: $timestamp,
        ];

        $this->recordOpenGeoData($contactMail, $event);

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{status: string, opened_at: mixed, clicked_at: mixed, sent_at: mixed, delivered_at: mixed}
     */
    private function clickUpdates(ContactMail $contactMail, array $event, CarbonImmutable $timestamp): array
    {
        $updates = [
            'status' => 'opened',
            'opened_at' => $contactMail->opened_at ?: $timestamp,
            'clicked_at' => $contactMail->clicked_at ?: $timestamp,
            'sent_at' => $contactMail->sent_at ?: $timestamp,
            'delivered_at' => $contactMail->delivered_at ?: $timestamp,
        ];

        $this->recordClick($contactMail, (array) data_get($event, 'click', []), $timestamp);
        $this->recordEngagement($contactMail, $timestamp);

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{status: string, bounced_at: CarbonImmutable}
     */
    private function bounceUpdates(ContactMail $contactMail, array $event, CarbonImmutable $timestamp): array
    {
        if ($this->isHardBounce($event)) {
            $this->blacklistContact($contactMail, $event, 'bounce', $timestamp);
        } else {
            $this->recordSoftBounce($contactMail, $event, $timestamp);
        }

        return [
            'bounced_at' => $timestamp,
            'status' => 'bounced',
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{status: string, complained_at: CarbonImmutable}
     */
    private function complaintUpdates(ContactMail $contactMail, array $event, CarbonImmutable $timestamp): array
    {
        $this->blacklistContact($contactMail, $event, 'complaint', $timestamp);

        return [
            'complained_at' => $timestamp,
            'status' => 'complained',
        ];
    }

    private function resolveStatus(string $current, string $candidate): string
    {
        if (in_array($candidate, self::TERMINAL_STATUSES, true)) {
            return $candidate;
        }

        if (in_array($current, self::TERMINAL_STATUSES, true)) {
            return $current;
        }

        $currentRank = self::PROGRESS_RANK[$current] ?? -1;
        $candidateRank = self::PROGRESS_RANK[$candidate] ?? -1;

        return $candidateRank > $currentRank ? $candidate : $current;
    }

    /**
     * Mails sent since the UUID tag carry `contact_mail_uuid`. Mails sent before
     * it carry the integer `contact_mail_id`. Both lookups stay inside the
     * clients that own the topic.
     *
     * @param  array<string, mixed>  $event
     * @param  array<int, int>  $clientIds
     */
    private function findContactMail(array $event, array $clientIds): ?ContactMail
    {
        $query = ContactMail::withoutGlobalScopes()
            ->with(['contact' => fn ($contacts) => $contacts->withoutGlobalScopes()])
            ->whereIn('client_id', $clientIds);

        $uuid = data_get($event, 'mail.tags.contact_mail_uuid.0');

        if (is_string($uuid) && Str::isUuid($uuid)) {
            return $query->where('uuid', $uuid)->first();
        }

        $id = data_get($event, 'mail.tags.contact_mail_id.0');

        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }

        return $query->whereKey((int) $id)->first();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function eventTimestamp(array $event): CarbonImmutable
    {
        $paths = [
            'delivery.timestamp',
            'open.timestamp',
            'click.timestamp',
            'bounce.timestamp',
            'complaint.timestamp',
            'reject.timestamp',
            'send.timestamp',
            'timestamp',
        ];

        foreach ($paths as $path) {
            $value = data_get($event, $path);

            if ($value) {
                return CarbonImmutable::parse($value);
            }
        }

        return now();
    }

    private function blacklistContact(ContactMail $contactMail, array $event, string $reason, CarbonImmutable $timestamp): void
    {
        $contact = $contactMail->contact;

        if (! $contact) {
            return;
        }

        $contact->webhooksSuppressed = true;
        $contact->forceFill([
            'unsubscribed_at' => $timestamp,
            'unsubscribed_by_contact' => false,
        ])->save();

        // Find the row by the unique key `(client_id, email)`. The author of
        // the contact can differ from the member who created the row.
        BlacklistedEmail::withoutGlobalScopes()->firstOrCreate(
            [
                'client_id' => $contact->client_id,
                'email' => Str::lower($contact->email),
            ],
            [
                'user_id' => $contact->user_id,
                'reason' => $reason,
            ],
        );
    }

    /**
     * Increments the soft-bounce count atomically by primary key and
     * suppresses the contact when the count reaches SOFT_BOUNCE_LIMIT.
     *
     * One mail counts one time. Only the event that fills an empty `bounced_at`
     * increments the count. A duplicate SQS message or a queue retry finds
     * `bounced_at` already set and does not increment the count.
     *
     * @param  array<string, mixed>  $event
     */
    private function recordSoftBounce(ContactMail $contactMail, array $event, CarbonImmutable $timestamp): void
    {
        if (! $contactMail->contact_id) {
            return;
        }

        $isFirstBounce = ContactMail::withoutGlobalScopes()
            ->whereKey($contactMail->id)
            ->whereNull('bounced_at')
            ->update(['bounced_at' => $timestamp]) === 1;

        if (! $isFirstBounce) {
            return;
        }

        $contacts = Contact::withoutGlobalScopes()->whereKey($contactMail->contact_id);

        $contacts->clone()->increment('soft_bounce_count');

        $softBounceCount = (int) $contacts->clone()->value('soft_bounce_count');

        if ($softBounceCount >= self::SOFT_BOUNCE_LIMIT) {
            $this->blacklistContact($contactMail, $event, 'soft_bounce', $timestamp);
        }
    }

    /**
     * Resets the soft-bounce count after a delivery. The condition makes the
     * common path (count already 0) write nothing.
     */
    private function resetSoftBounceCount(ContactMail $contactMail): void
    {
        if (! $contactMail->contact_id) {
            return;
        }

        Contact::withoutGlobalScopes()
            ->whereKey($contactMail->contact_id)
            ->where('soft_bounce_count', '>', 0)
            ->update(['soft_bounce_count' => 0]);
    }

    /**
     * The mail, the link and the click time identify one click. A duplicate
     * SQS message carries the same three values, so it adds no second row.
     *
     * The link name comes from the SES link tag of the anchor. The user agent
     * and the IP address are kept for a later bot filter, because link
     * scanners (Safe Links, Mimecast) also click.
     *
     * @param  array<string, mixed>  $click
     */
    private function recordClick(ContactMail $contactMail, array $click, CarbonImmutable $timestamp): void
    {
        if (! $contactMail->contact_id) {
            return;
        }

        $link = data_get($click, 'link');
        $userAgent = data_get($click, 'userAgent');
        $ipAddress = data_get($click, 'ipAddress');

        ContactMailClick::query()->firstOrCreate(
            [
                'contact_mail_id' => $contactMail->id,
                'link' => is_string($link) ? $link : null,
                'clicked_at' => $timestamp,
            ],
            [
                'contact_id' => $contactMail->contact_id,
                'link_name' => NamedLink::fromSesClick($click),
                'user_agent' => is_string($userAgent) ? Str::limit($userAgent, 512, '') : null,
                'ip_address' => is_string($ipAddress) ? Str::limit($ipAddress, 45, '') : null,
            ],
        );
    }

    /**
     * A click is the only engagement signal. Opens are not, because Apple Mail
     * Privacy Protection fakes them. The click moves `last_engaged_at` forward
     * only, and always ends a sunset.
     */
    private function recordEngagement(ContactMail $contactMail, CarbonImmutable $timestamp): void
    {
        if (! $contactMail->contact_id) {
            return;
        }

        $contacts = fn () => Contact::withoutGlobalScopes()->whereKey($contactMail->contact_id)->toBase();

        $updated = $contacts()
            ->where(fn ($query) => $query->whereNull('last_engaged_at')->orWhere('last_engaged_at', '<', $timestamp))
            ->update(['last_engaged_at' => $timestamp, 'sunset_at' => null]);

        if ($updated === 0) {
            $contacts()->whereNotNull('sunset_at')->update(['sunset_at' => null]);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function recordOpenGeoData(ContactMail $contactMail, array $event): void
    {
        try {
            if ($contactMail->open_ip_address) {
                return;
            }

            $ipAddress = data_get($event, 'open.ipAddress');
            $userAgent = data_get($event, 'open.userAgent');

            if (! $ipAddress) {
                return;
            }

            $geo = $this->geoIpService->lookup($ipAddress);

            $contactMail->update([
                'open_ip_address' => $ipAddress,
                'open_user_agent' => $userAgent,
                'open_timezone' => $geo['timezone'],
                'open_country' => $geo['country'],
                'open_city' => $geo['city'],
            ]);

            if (($geo['timezone'] || $geo['country']) && $contactMail->contact) {
                $contactMail->contact->update(array_filter([
                    'timezone' => $geo['timezone'],
                    'country' => $geo['country'],
                ]));
            }
        } catch (Exception $e) {
            report($e);
        }
    }

    /**
     * Only a Permanent bounce is hard. Transient, Undetermined, and a missing
     * or unknown bounce type are soft.
     *
     * @param  array<string, mixed>  $event
     */
    private function isHardBounce(array $event): bool
    {
        $bounceType = Str::lower((string) data_get($event, 'bounce.bounceType', ''));

        return $bounceType === self::BOUNCE_TYPE_PERMANENT;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function updateEmailLog(array $event, string $status, int $clientId): void
    {
        try {
            $messageId = data_get($event, 'mail.messageId');

            if (! $messageId) {
                return;
            }

            DB::table('email_logs')
                ->where('client_id', $clientId)
                ->where('message_id', $messageId)
                ->update([
                    'status' => $status,
                    'sns_payload' => json_encode($event),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            Log::error('ProcessSesEventJob: failed to update email log', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractEventsFromSns(array $payload): array
    {
        $message = $payload['Message'] ?? '';
        $decoded = json_decode((string) $message, true);

        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['events']) && is_array($decoded['events'])) {
            return $decoded['events'];
        }

        if (isset($decoded['eventType'])) {
            return [$decoded];
        }

        return [];
    }
}
