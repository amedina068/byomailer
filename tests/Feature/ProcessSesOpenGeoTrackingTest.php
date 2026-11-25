<?php

namespace Tests\Feature;

use App\Jobs\ProcessSesEventJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Services\GeoIpService;
use App\Services\SequenceStepScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\BuildsSesNotifications;
use Tests\TestCase;

class ProcessSesOpenGeoTrackingTest extends TestCase
{
    use BuildsSesNotifications;
    use RefreshDatabase;

    public function test_open_event_stores_ip_and_user_agent_and_geo_data(): void
    {
        $contactMail = ContactMail::factory()->sent()->create();

        $this->mockGeoIpService('America/Chicago', 'US', 'Chicago');

        $this->dispatchOpenEvent($contactMail, '203.0.113.50', 'Mozilla/5.0 (Macintosh)');

        $contactMail->refresh();

        $this->assertSame('203.0.113.50', $contactMail->open_ip_address);
        $this->assertSame('Mozilla/5.0 (Macintosh)', $contactMail->open_user_agent);
        $this->assertSame('America/Chicago', $contactMail->open_timezone);
        $this->assertSame('US', $contactMail->open_country);
        $this->assertSame('Chicago', $contactMail->open_city);
    }

    public function test_open_event_propagates_timezone_to_contact(): void
    {
        $contact = Contact::factory()->create(['timezone' => null, 'country' => null]);
        $contactMail = ContactMail::factory()->sent()->create(['contact_id' => $contact->id]);

        $this->mockGeoIpService('Europe/London', 'GB', 'London');

        $this->dispatchOpenEvent($contactMail, '203.0.113.50');

        $contact->refresh();

        $this->assertSame('Europe/London', $contact->timezone);
        $this->assertSame('GB', $contact->country);
    }

    public function test_open_event_updates_contact_timezone_on_subsequent_opens(): void
    {
        $contact = Contact::factory()->create(['timezone' => 'America/New_York', 'country' => 'US']);
        $contactMail = ContactMail::factory()->sent()->create([
            'contact_id' => $contact->id,
        ]);

        $this->mockGeoIpService('Asia/Tokyo', 'JP', 'Tokyo');

        $this->dispatchOpenEvent($contactMail, '203.0.113.51');

        $contact->refresh();

        $this->assertSame('Asia/Tokyo', $contact->timezone);
        $this->assertSame('JP', $contact->country);
    }

    public function test_open_event_does_not_overwrite_existing_geo_data_on_same_contact_mail(): void
    {
        $contactMail = ContactMail::factory()->sent()->create([
            'open_ip_address' => '198.51.100.10',
            'open_user_agent' => 'OriginalAgent',
            'open_timezone' => 'America/New_York',
            'open_country' => 'US',
            'open_city' => 'New York',
        ]);

        $this->mockGeoIpService('Asia/Tokyo', 'JP', 'Tokyo');

        $this->dispatchOpenEvent($contactMail, '203.0.113.50', 'NewAgent');

        $contactMail->refresh();

        $this->assertSame('198.51.100.10', $contactMail->open_ip_address);
        $this->assertSame('OriginalAgent', $contactMail->open_user_agent);
        $this->assertSame('America/New_York', $contactMail->open_timezone);
    }

    public function test_open_event_without_ip_does_not_store_geo_data(): void
    {
        $contactMail = ContactMail::factory()->sent()->create();

        $this->mockGeoIpService();

        $payload = $this->buildSnsPayload($contactMail->id, 'OPEN', [
            'open' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);

        (new ProcessSesEventJob($payload))->handle(
            app(SequenceStepScheduler::class),
            app(GeoIpService::class)
        );

        $contactMail->refresh();

        $this->assertNull($contactMail->open_ip_address);
        $this->assertNull($contactMail->open_timezone);
    }

    public function test_click_event_does_not_store_geo_data(): void
    {
        $contactMail = ContactMail::factory()->sent()->create();

        $this->mockGeoIpService('America/Chicago', 'US', 'Chicago');

        $payload = $this->buildSnsPayload($contactMail->id, 'CLICK', [
            'click' => [
                'ipAddress' => '203.0.113.50',
                'userAgent' => 'Mozilla/5.0',
                'link' => 'https://example.com',
                'timestamp' => now()->toIso8601String(),
            ],
        ]);

        (new ProcessSesEventJob($payload))->handle(
            app(SequenceStepScheduler::class),
            app(GeoIpService::class)
        );

        $contactMail->refresh();

        $this->assertNull($contactMail->open_ip_address);
        $this->assertNull($contactMail->open_timezone);
    }

    private function mockGeoIpService(?string $timezone = null, ?string $country = null, ?string $city = null): void
    {
        $mock = Mockery::mock(GeoIpService::class);
        $mock->shouldReceive('lookup')->andReturn([
            'timezone' => $timezone,
            'country' => $country,
            'city' => $city,
        ]);

        $this->app->instance(GeoIpService::class, $mock);
    }

    private function dispatchOpenEvent(ContactMail $contactMail, string $ip, string $userAgent = 'TestAgent/1.0'): void
    {
        $payload = $this->buildSnsPayload($contactMail->id, 'OPEN', [
            'open' => [
                'ipAddress' => $ip,
                'userAgent' => $userAgent,
                'timestamp' => now()->toIso8601String(),
            ],
        ]);

        (new ProcessSesEventJob($payload))->handle(
            app(SequenceStepScheduler::class),
            app(GeoIpService::class)
        );
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    private function buildSnsPayload(int $contactMailId, string $eventType, array $eventData = []): array
    {
        return [
            'Type' => 'Notification',
            'TopicArn' => $this->sesTopicArnFor(ContactMail::withoutGlobalScopes()->findOrFail($contactMailId)->client_id),
            'Message' => json_encode(array_merge([
                'eventType' => $eventType,
                'mail' => [
                    'tags' => [
                        'contact_mail_id' => [(string) $contactMailId],
                    ],
                ],
            ], $eventData)),
        ];
    }
}
