<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\SunsetPolicy;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApplySunsetCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(now()->startOfSecond());

        $this->user = User::factory()->create();
        $this->user->client->forceFill(['sunset_inactive_days' => 30])->save();
    }

    public function test_contact_with_recent_click_is_not_sunset(): void
    {
        $contact = $this->contact(['created_at' => now()->subDays(90), 'last_engaged_at' => now()->subDays(10)], mails: 5);

        $this->artisan('contacts:apply-sunset')->assertSuccessful();

        $this->assertNull($contact->fresh()->sunset_at);
    }

    public function test_contact_with_old_click_and_three_mails_in_window_is_sunset(): void
    {
        $contact = $this->contact(['created_at' => now()->subDays(90), 'last_engaged_at' => now()->subDays(40)], mails: 3);

        $this->artisan('contacts:apply-sunset')->assertSuccessful();

        $this->assertTrue($contact->fresh()->sunset_at->equalTo(now()));
    }

    public function test_never_clicked_contact_with_three_mails_in_window_is_sunset(): void
    {
        $contact = $this->contact(['created_at' => now()->subDays(40)], mails: 3);

        $this->artisan('contacts:apply-sunset')->assertSuccessful();

        $this->assertTrue($contact->fresh()->sunset_at->equalTo(now()));
    }

    public function test_never_clicked_contact_with_two_mails_in_window_is_not_sunset(): void
    {
        $contact = $this->contact(['created_at' => now()->subDays(40)], mails: 2);
        ContactMail::factory()->for($contact)->create(['sent_at' => now()->subDays(35)]);

        $this->artisan('contacts:apply-sunset')->assertSuccessful();

        $this->assertNull($contact->fresh()->sunset_at);
    }

    public function test_recent_never_clicked_contact_is_not_sunset(): void
    {
        $contact = $this->contact(['created_at' => now()->subDays(20)], mails: 3);

        $this->artisan('contacts:apply-sunset')->assertSuccessful();

        $this->assertNull($contact->fresh()->sunset_at);
    }

    public function test_unsubscribed_contact_is_not_sunset(): void
    {
        $contact = $this->contact(['created_at' => now()->subDays(40), 'unsubscribed_at' => now()->subDays(5)], mails: 3);

        $this->artisan('contacts:apply-sunset')->assertSuccessful();

        $this->assertNull($contact->fresh()->sunset_at);
    }

    public function test_already_sunset_contact_keeps_its_sunset_date(): void
    {
        $contact = $this->contact(['created_at' => now()->subDays(40), 'sunset_at' => now()->subDays(2)], mails: 3);

        $this->artisan('contacts:apply-sunset')->assertSuccessful();

        $this->assertTrue($contact->fresh()->sunset_at->equalTo(now()->subDays(2)));
    }

    public function test_client_without_setting_is_untouched(): void
    {
        $this->user->client->forceFill(['sunset_inactive_days' => null])->save();
        $contact = $this->contact(['created_at' => now()->subDays(400)], mails: 3);

        $this->artisan('contacts:apply-sunset')->assertSuccessful();

        $this->assertNull($contact->fresh()->sunset_at);
    }

    public function test_each_client_uses_its_own_setting(): void
    {
        $inactive = $this->contact(['created_at' => now()->subDays(40)], mails: 3);

        $otherUser = User::factory()->create();
        $otherUser->client->forceFill(['sunset_inactive_days' => 60])->save();
        $otherContact = $this->contact(['created_at' => now()->subDays(40)], mails: 3, user: $otherUser);

        $this->artisan('contacts:apply-sunset')->assertSuccessful();

        $this->assertNotNull($inactive->fresh()->sunset_at);
        $this->assertNull($otherContact->fresh()->sunset_at);
    }

    public function test_sunset_preview_count_matches_the_sweep(): void
    {
        $this->user->client->forceFill(['sunset_inactive_days' => null])->save();

        $this->contact(['created_at' => now()->subDays(90), 'last_engaged_at' => now()->subDays(40)], mails: 3);
        $this->contact(['created_at' => now()->subDays(40)], mails: 3);
        $this->contact(['created_at' => now()->subDays(90), 'last_engaged_at' => now()->subDays(10)], mails: 3);
        $this->contact(['created_at' => now()->subDays(40), 'sunset_at' => now()->subDays(2)], mails: 3);
        $this->contact(['created_at' => now()->subDays(40), 'unsubscribed_at' => now()->subDays(5)], mails: 3);

        $this->actingAs($this->user)
            ->patch(route('profile.update'), [
                'name' => $this->user->name,
                'email' => $this->user->email,
                'timezone' => 'UTC',
                'sunset_inactive_days' => 30,
            ])
            ->assertSessionHasNoErrors();

        $cacheKey = SunsetPolicy::previewCacheKey($this->user->client_id, 30);
        $this->assertFalse(Cache::has($cacheKey), 'The save request must not count candidates.');

        $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('sunsetPreview')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('sunsetPreview', ['count' => 2, 'days' => 30])
                )
            );

        $this->assertTrue(Cache::has($cacheKey));

        $this->artisan('contacts:apply-sunset')->expectsOutput('Marked 2 contact(s) as sunset.');
    }

    public function test_sweep_forgets_the_sunset_preview_cache(): void
    {
        $this->contact(['created_at' => now()->subDays(40)], mails: 3);
        $cacheKey = SunsetPolicy::previewCacheKey($this->user->client_id, 30);
        Cache::put($cacheKey, 1, 600);

        $this->artisan('contacts:apply-sunset')->assertSuccessful();

        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_sweep_is_scheduled_daily_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'contacts:apply-sunset'));

        $this->assertNotNull($event);
        $this->assertSame('0 4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_sweep_fails_pending_sequence_mails_of_sunset_contacts(): void
    {
        $contact = $this->contact(['created_at' => now()->subDays(40)], mails: 3);
        $pending = $this->stepMail($contact, ['status' => 'pending', 'sent_at' => null]);
        $sent = $this->stepMail($contact, ['status' => 'sent', 'sent_at' => now()->subDay()]);

        $otherUser = User::factory()->create();
        $otherContact = Contact::factory()->for($otherUser)->create();
        $otherPending = $this->stepMail($otherContact, ['status' => 'pending', 'sent_at' => null]);

        $this->artisan('contacts:apply-sunset')->assertSuccessful();

        $pending->refresh();
        $this->assertSame('failed', $pending->status);
        $this->assertSame('sunset', $pending->failure_reason);
        $this->assertSame('sent', $sent->fresh()->status);
        $this->assertSame('pending', $otherPending->fresh()->status);
        $this->assertNull($otherPending->fresh()->failure_reason);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stepMail(Contact $contact, array $attributes): ContactMail
    {
        return ContactMail::factory()
            ->for($contact)
            ->for(SequenceStep::factory(), 'mailable')
            ->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contact(array $attributes, int $mails, ?User $user = null): Contact
    {
        $user ??= $this->user;
        $contact = Contact::factory()->for($user)->create($attributes);
        for ($i = 0; $i < $mails; $i++) {
            ContactMail::factory()
                ->for($contact)
                ->for(Newsletter::factory()->for($user), 'mailable')
                ->create(['sent_at' => now()->subDays(5)]);
        }

        return $contact;
    }
}
