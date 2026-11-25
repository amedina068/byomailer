<?php

namespace Tests\Feature;

use App\Jobs\ProcessSesEventJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\Newsletter;
use App\Models\User;
use Aws\Sns\MessageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class SesWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const string TOPIC_ARN = 'arn:aws:sns:us-east-1:123456789012:mailautomation-1-events';

    private const string CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-test.pem';

    private OpenSSLAsymmetricKey $privateKey;

    private string $certificatePem;

    /**
     * @var array<int, string>
     */
    private array $requestedCertificateUrls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->privateKey = $this->newPrivateKey();
        $csr = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $this->privateKey, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $this->privateKey, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($certificate, $certificatePem);
        $this->certificatePem = $certificatePem;

        $this->app->instance(MessageValidator::class, new MessageValidator(function (string $url): string {
            $this->requestedCertificateUrls[] = $url;

            return $this->certificatePem;
        }));

        EmailCredential::factory()->create(['sns_topic_arn' => self::TOPIC_ARN]);
    }

    public function test_valid_signature_version_2_notification_dispatches_job(): void
    {
        Queue::fake();
        $message = $this->signedNotification();

        $this->postSns($message)
            ->assertOk()
            ->assertExactJson(['queued' => true]);

        Queue::assertPushed(ProcessSesEventJob::class, fn (ProcessSesEventJob $job): bool => $job->payload === $message);
    }

    public function test_valid_signature_version_1_notification_dispatches_job(): void
    {
        Queue::fake();

        $this->postSns($this->signedNotification(signatureVersion: '1'))->assertOk();

        Queue::assertPushed(ProcessSesEventJob::class, 1);
    }

    public function test_valid_notification_with_subject_dispatches_job(): void
    {
        Queue::fake();

        $this->postSns($this->signedNotification(extra: ['Subject' => 'Amazon SES Email Event Notification']))->assertOk();

        Queue::assertPushed(ProcessSesEventJob::class, 1);
    }

    public function test_valid_signed_delivery_updates_contact_mail(): void
    {
        $user = $this->topicOwner();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create(['status' => 'sent', 'delivered_at' => null]);

        $this->postSns($this->signedNotification($this->sesEvent('DELIVERY', $contactMail->id, [
            'delivery' => ['timestamp' => now()->toISOString()],
        ])))->assertOk();

        $this->assertSame('delivered', $contactMail->refresh()->status);
    }

    public function test_signed_event_on_the_topic_of_one_tenant_cannot_change_a_mail_of_another_tenant(): void
    {
        $victim = User::factory()->create();
        $victimContact = Contact::factory()->for($victim)->create(['email' => 'victim@example.test']);
        $victimMail = ContactMail::factory()
            ->for($victimContact)
            ->for(Newsletter::factory()->for($victim)->create(), 'mailable')
            ->create(['status' => 'delivered']);
        $this->assertNotSame($this->topicOwner()->client_id, $victim->client_id);

        $this->postSns($this->signedNotification($this->sesEvent('BOUNCE', $victimMail->id, [
            'bounce' => ['bounceType' => 'Permanent', 'bounceSubType' => 'NoEmail'],
        ])))->assertOk();

        $this->assertSame('delivered', $victimMail->refresh()->status);
        $this->assertNull($victimContact->refresh()->unsubscribed_at);
        $this->assertDatabaseMissing('blacklisted_emails', ['email' => 'victim@example.test']);
    }

    public function test_replayed_message_is_queued_only_once(): void
    {
        Queue::fake();
        $message = $this->signedNotification();

        $this->postSns($message)->assertOk()->assertExactJson(['queued' => true]);
        $this->postSns($message)->assertOk()->assertExactJson(['queued' => false]);

        Queue::assertPushed(ProcessSesEventJob::class, 1);
    }

    public function test_signed_message_older_than_the_replay_window_is_rejected(): void
    {
        Queue::fake();
        $message = $this->signedNotification(extra: ['Timestamp' => now()->subDay()->subMinute()->toISOString()]);

        $this->postSns($message)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_tampered_message_is_rejected(): void
    {
        Queue::fake();
        $message = $this->signedNotification($this->sesEvent('DELIVERY', 1));
        $message['Message'] = json_encode($this->sesEvent('BOUNCE', 1, [
            'bounce' => ['bounceType' => 'Permanent'],
        ]));

        $this->postSns($message)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_tampered_topic_arn_is_rejected(): void
    {
        Queue::fake();
        EmailCredential::factory()->create(['sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:other-events']);
        $message = $this->signedNotification();
        $message['TopicArn'] = 'arn:aws:sns:us-east-1:123456789012:other-events';

        $this->postSns($message)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_missing_signature_fields_are_rejected(): void
    {
        Queue::fake();
        $message = $this->signedNotification();
        unset($message['Signature'], $message['SigningCertURL'], $message['SignatureVersion']);

        $this->postSns($message)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_signature_field_that_is_not_a_string_is_rejected(): void
    {
        Queue::fake();
        $message = $this->signedNotification();
        $message['Signature'] = ['not', 'a', 'string'];

        $this->postSns($message)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_unsigned_direct_events_payload_is_rejected(): void
    {
        Queue::fake();

        $this->postSns(['events' => [$this->sesEvent('COMPLAINT', 1)]])->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_body_that_is_not_json_is_rejected(): void
    {
        Queue::fake();

        $this->call('POST', route('webhooks.ses'), [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'not json')
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_signing_certificate_url_on_another_host_is_rejected(): void
    {
        Queue::fake();
        $message = $this->signedNotification(certUrl: 'https://attacker.example.com/SimpleNotificationService-test.pem');

        $this->postSns($message)->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame([], $this->requestedCertificateUrls);
    }

    public function test_signing_certificate_url_over_http_is_rejected(): void
    {
        Queue::fake();
        $message = $this->signedNotification(certUrl: 'http://sns.us-east-1.amazonaws.com/SimpleNotificationService-test.pem');

        $this->postSns($message)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_signature_from_another_key_is_rejected(): void
    {
        Queue::fake();
        $this->privateKey = $this->newPrivateKey();

        $this->postSns($this->signedNotification())->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_validly_signed_message_from_unknown_topic_is_rejected(): void
    {
        Queue::fake();
        $message = $this->signedNotification(topicArn: 'arn:aws:sns:us-east-1:999999999999:attacker-events');

        $this->postSns($message)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_subscription_confirmation_from_known_topic_is_not_confirmed(): void
    {
        Queue::fake();
        $message = $this->signedMessage([
            'Type' => 'SubscriptionConfirmation',
            'MessageId' => 'confirmation-1',
            'Token' => 'token-123',
            'TopicArn' => self::TOPIC_ARN,
            'Message' => 'You have chosen to subscribe to the topic.',
            'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&Token=token-123',
            'Timestamp' => now()->toISOString(),
        ]);

        $this->postSns($message)->assertOk();

        Http::assertNothingSent();
        Queue::assertPushed(ProcessSesEventJob::class, 1);
    }

    public function test_rejection_logs_one_warning_without_the_payload_body(): void
    {
        Queue::fake();
        Log::spy();
        $message = $this->signedNotification($this->sesEvent('DELIVERY', 1, ['secret-marker' => 'do-not-log']));
        $message['Signature'] = base64_encode('forged');

        $this->postSns($message)->assertForbidden();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $logMessage, array $context = []): bool => ! str_contains($logMessage.json_encode($context), 'do-not-log'));
    }

    public function test_default_validator_fetches_the_certificate_over_http_once(): void
    {
        Queue::fake();
        $this->app->forgetInstance(MessageValidator::class);
        Http::fake([self::CERT_URL => Http::response($this->certificatePem)]);

        $this->postSns($this->signedNotification())->assertOk();
        $this->postSns($this->signedNotification())->assertOk();

        Http::assertSentCount(1);
        Queue::assertPushed(ProcessSesEventJob::class, 2);
    }

    public function test_failed_certificate_fetch_returns_server_error_so_sns_retries(): void
    {
        Queue::fake();
        $this->app->forgetInstance(MessageValidator::class);
        Http::fake([self::CERT_URL => Http::response('unavailable', 503)]);

        $this->postSns($this->signedNotification())->assertServerError();

        Queue::assertNothingPushed();
    }

    public function test_the_worker_confirms_a_signed_subscription_of_a_known_topic(): void
    {
        $subscribeUrl = 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn='.self::TOPIC_ARN.'&Token=abc';
        Http::fake([$subscribeUrl => Http::response('<ConfirmSubscriptionResponse/>')]);

        ProcessSesEventJob::dispatchSync($this->signedConfirmation($subscribeUrl));

        Http::assertSent(fn ($request): bool => $request->url() === $subscribeUrl);
    }

    public function test_the_worker_does_not_confirm_a_subscription_of_an_unknown_topic(): void
    {
        $topicArn = 'arn:aws:sns:us-east-1:999999999999:mailautomation-9-events';
        $subscribeUrl = 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn='.$topicArn.'&Token=abc';
        Http::fake();

        ProcessSesEventJob::dispatchSync($this->signedConfirmation($subscribeUrl, $topicArn));

        Http::assertNothingSent();
    }

    public function test_the_worker_does_not_confirm_a_subscription_with_a_bad_signature(): void
    {
        $message = $this->signedConfirmation('https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&Token=abc');
        $message['SubscribeURL'] = 'https://attacker.example/collect';
        Http::fake();

        ProcessSesEventJob::dispatchSync($message);

        Http::assertNothingSent();
    }

    /**
     * @return array<string, string>
     */
    private function signedConfirmation(string $subscribeUrl, string $topicArn = self::TOPIC_ARN): array
    {
        return $this->signedMessage([
            'Type' => 'SubscriptionConfirmation',
            'MessageId' => fake()->uuid(),
            'Token' => 'abc',
            'TopicArn' => $topicArn,
            'Message' => 'You have chosen to subscribe to the topic.',
            'SubscribeURL' => $subscribeUrl,
            'Timestamp' => now()->toISOString(),
        ]);
    }

    private function topicOwner(): User
    {
        return EmailCredential::withoutGlobalScopes()->where('sns_topic_arn', self::TOPIC_ARN)->firstOrFail()->user;
    }

    private function newPrivateKey(): OpenSSLAsymmetricKey
    {
        return openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
    }

    /**
     * SNS posts the envelope as a raw JSON body with a text/plain content type.
     *
     * @param  array<string, mixed>  $message
     */
    private function postSns(array $message): TestResponse
    {
        return $this->call(
            'POST',
            route('webhooks.ses'),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain; charset=UTF-8'],
            json_encode($message),
        );
    }

    /**
     * @param  array<string, mixed>|null  $event
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function signedNotification(
        ?array $event = null,
        string $signatureVersion = '2',
        string $topicArn = self::TOPIC_ARN,
        string $certUrl = self::CERT_URL,
        array $extra = [],
    ): array {
        return $this->signedMessage(array_merge([
            'Type' => 'Notification',
            'MessageId' => fake()->uuid(),
            'TopicArn' => $topicArn,
            'Message' => json_encode($event ?? $this->sesEvent('DELIVERY', 1)),
            'Timestamp' => now()->toISOString(),
        ], $extra), $signatureVersion, $certUrl);
    }

    /**
     * Sign a message with the AWS SNS string-to-sign format.
     *
     * @param  array<string, string>  $message
     * @return array<string, string>
     */
    private function signedMessage(array $message, string $signatureVersion = '2', string $certUrl = self::CERT_URL): array
    {
        $signableKeys = $message['Type'] === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

        $stringToSign = '';

        foreach ($signableKeys as $key) {
            if (isset($message[$key])) {
                $stringToSign .= "{$key}\n{$message[$key]}\n";
            }
        }

        $algorithm = $signatureVersion === '1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;
        openssl_sign($stringToSign, $signature, $this->privateKey, $algorithm);

        return array_merge($message, [
            'SignatureVersion' => $signatureVersion,
            'Signature' => base64_encode($signature),
            'SigningCertURL' => $certUrl,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sesEvent(string $type, int $contactMailId, array $overrides = []): array
    {
        return array_merge([
            'eventType' => $type,
            'mail' => [
                'tags' => [
                    'contact_mail_id' => [(string) $contactMailId],
                ],
            ],
            'timestamp' => now()->toISOString(),
        ], $overrides);
    }
}
