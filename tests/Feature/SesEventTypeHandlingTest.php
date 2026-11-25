<?php

namespace Tests\Feature;

use App\Enums\SesEventType;
use App\Jobs\ProcessSesEventJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\SesConfigurationSetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSesNotifications;
use Tests\TestCase;

class SesEventTypeHandlingTest extends TestCase
{
    use BuildsSesNotifications;
    use RefreshDatabase;

    /**
     * The `eventType` names in the SES event publishing payload, from the AWS examples.
     */
    private const array PAYLOAD_NAMES = [
        'SEND' => 'Send',
        'DELIVERY' => 'Delivery',
        'OPEN' => 'Open',
        'CLICK' => 'Click',
        'BOUNCE' => 'Bounce',
        'COMPLAINT' => 'Complaint',
        'REJECT' => 'Reject',
        'RENDERING_FAILURE' => 'Rendering Failure',
        'DELIVERY_DELAY' => 'DeliveryDelay',
    ];

    /**
     * Each event type that a configuration set publishes changes the contact mail.
     * The test sends the name that SES writes in the event payload.
     */
    public function test_each_published_event_type_has_a_handler(): void
    {
        $published = app(SesConfigurationSetService::class)->getEventTypesForTracking(true, true);

        $this->assertNotEmpty($published);

        foreach ($published as $value) {
            Log::spy();

            $contactMail = $this->makeMail(['status' => 'sent', 'sent_at' => null]);
            $before = Arr::except($contactMail->refresh()->getAttributes(), 'updated_at');

            ProcessSesEventJob::dispatchSync($this->sesNotification([
                $this->event(self::PAYLOAD_NAMES[$value], $contactMail->id),
            ]));

            $after = Arr::except($contactMail->refresh()->getAttributes(), 'updated_at');
            $this->assertNotEquals($before, $after, "The {$value} event type has no handler.");
            Log::shouldNotHaveReceived('warning');
        }
    }

    public function test_a_rendering_failure_with_the_name_from_ses_fails_the_mail(): void
    {
        $contactMail = $this->makeMail(['status' => 'pending']);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->event('Rendering Failure', $contactMail->id, [
                'failure' => ['errorMessage' => 'Attribute name is missing'],
            ]),
        ]));

        $contactMail->refresh();
        $this->assertSame('failed', $contactMail->status);
        $this->assertSame('Attribute name is missing', $contactMail->failure_reason);
    }

    public function test_a_delivery_delay_changes_nothing_and_logs_nothing(): void
    {
        Log::spy();

        $contactMail = $this->makeMail(['status' => 'sent']);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->event('DeliveryDelay', $contactMail->id),
        ]));

        $this->assertSame('sent', $contactMail->refresh()->status);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_an_unknown_event_type_is_logged_and_not_applied(): void
    {
        Log::spy();

        $contactMail = $this->makeMail(['status' => 'sent']);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->event('Subscription', $contactMail->id),
        ]));

        $this->assertSame('sent', $contactMail->refresh()->status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'ProcessSesEventJob: unknown SES event type'
                && $context['event_type'] === 'Subscription')
            ->once();
    }

    /**
     * SES writes one name in the event payload and another name in the configuration set API.
     */
    #[DataProvider('eventNames')]
    public function test_it_reads_the_event_type_from_both_names(string $name, SesEventType $expected): void
    {
        $this->assertSame($expected, SesEventType::fromEventName($name));
    }

    /**
     * @return array<string, array{string, SesEventType}>
     */
    public static function eventNames(): array
    {
        $names = [];

        foreach (SesEventType::cases() as $type) {
            $names[$type->value] = [$type->value, $type];
            $names[self::PAYLOAD_NAMES[$type->value]] = [self::PAYLOAD_NAMES[$type->value], $type];
        }

        return $names;
    }

    public function test_it_returns_null_for_an_unknown_event_type(): void
    {
        $this->assertNull(SesEventType::fromEventName('Subscription'));
        $this->assertNull(SesEventType::fromEventName(''));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeMail(array $overrides = []): ContactMail
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        return ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create($overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(string $type, int $contactMailId, array $overrides = []): array
    {
        return array_merge([
            'eventType' => $type,
            'mail' => [
                'messageId' => 'ses-message-id',
                'tags' => [
                    'contact_mail_id' => [$contactMailId],
                ],
            ],
            'timestamp' => now()->toISOString(),
        ], $overrides);
    }
}
