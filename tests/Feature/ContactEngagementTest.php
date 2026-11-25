<?php

namespace Tests\Feature;

use App\Jobs\ProcessSesEventJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\Newsletter;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSesNotifications;
use Tests\TestCase;

class ContactEngagementTest extends TestCase
{
    use BuildsSesNotifications;
    use RefreshDatabase;

    public function test_click_sets_last_engaged_at_and_clears_sunset_at(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail(['sunset_at' => now()->subDay()]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->event('CLICK', $contactMail->id, [
            'click' => ['timestamp' => now()->toISOString(), 'link' => 'https://example.com'],
        ])]));

        $contact = $contactMail->contact->fresh();
        $this->assertTrue($contact->last_engaged_at->equalTo(now()));
        $this->assertNull($contact->sunset_at);
    }

    public function test_older_click_keeps_later_last_engaged_at_and_clears_sunset_at(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail(['last_engaged_at' => now(), 'sunset_at' => now()]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->event('CLICK', $contactMail->id, [
            'click' => ['timestamp' => now()->subDays(5)->toISOString(), 'link' => 'https://example.com'],
        ])]));

        $contact = $contactMail->contact->fresh();
        $this->assertTrue($contact->last_engaged_at->equalTo(now()));
        $this->assertNull($contact->sunset_at);
    }

    public function test_open_does_not_change_last_engaged_at(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail(['sunset_at' => now()->subDay()]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->event('OPEN', $contactMail->id, [
            'open' => ['timestamp' => now()->toISOString()],
        ])]));

        $contact = $contactMail->contact->fresh();
        $this->assertNull($contact->last_engaged_at);
        $this->assertNotNull($contact->sunset_at);
    }

    public function test_backfill_sets_last_click_and_is_idempotent(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail();
        $contact = $contactMail->contact;
        $this->click($contactMail, now()->subDays(20));
        $this->click($contactMail, now()->subDays(3));
        $neverClicked = Contact::factory()->for($contact->user)->create();

        $this->artisan('contacts:backfill-last-engaged')->assertSuccessful();
        $first = $contact->fresh()->last_engaged_at;

        $this->artisan('contacts:backfill-last-engaged')->assertSuccessful();

        $this->assertTrue($first->equalTo(now()->subDays(3)));
        $this->assertTrue($contact->fresh()->last_engaged_at->equalTo($first));
        $this->assertNull($neverClicked->fresh()->last_engaged_at);
    }

    public function test_backfill_only_moves_last_engaged_at_forward(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail(['last_engaged_at' => now()->subDay()]);
        $this->click($contactMail, now()->subDays(10));

        $this->artisan('contacts:backfill-last-engaged')->assertSuccessful();

        $this->assertTrue($contactMail->contact->fresh()->last_engaged_at->equalTo(now()->subDay()));
    }

    public function test_backfill_migration_sets_last_engaged_at_from_historical_clicks(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail();
        $this->click($contactMail, now()->subDays(7));

        $migration = require database_path('migrations/2026_09_22_000003_backfill_contacts_last_engaged_at.php');
        $migration->up();

        $this->assertTrue($contactMail->contact->fresh()->last_engaged_at->equalTo(now()->subDays(7)));
    }

    /**
     * @param  array<string, mixed>  $contactOverrides
     */
    private function makeMail(array $contactOverrides = []): ContactMail
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create($contactOverrides);
        $newsletter = Newsletter::factory()->for($user)->create();

        return ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create()
            ->setRelation('contact', $contact);
    }

    private function click(ContactMail $contactMail, CarbonInterface $at): void
    {
        ContactMailClick::factory()->create([
            'contact_mail_id' => $contactMail->id,
            'contact_id' => $contactMail->contact_id,
            'clicked_at' => $at,
        ]);
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
                'tags' => [
                    'contact_mail_id' => [$contactMailId],
                ],
            ],
            'timestamp' => now()->toISOString(),
        ], $overrides);
    }
}
