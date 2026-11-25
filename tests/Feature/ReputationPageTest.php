<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\User;
use App\Services\SesClientManager;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class ReputationPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(SesClientManager::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('clientFor', 'v2ClientFor', 'fetchAndUpdateQuota', 'fetchAndUpdatePermissions');
        });
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('reputation.show'))->assertRedirect(route('login'));
    }

    public function test_reputation_rates_ignore_another_client(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->seedMails($userA, 100, 1, 0, now()->subHours(2));
        $this->seedMails($userA, 10, 0, 0, now()->subDays(3));
        $this->seedMails($userA, 10, 0, 0, now()->subDays(20));
        $this->seedMails($userA, 10, 10, 10, now()->subDays(40));
        $this->seedMails($userB, 100, 50, 10, now()->subHours(2));

        $this->actingAs($userA)
            ->get(route('reputation.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reputation')
                ->missing('rates')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('rates.warn', false)
                    ->where('rates.windows.0.key', '24h')
                    ->where('rates.windows.0.sent', 100)
                    ->where('rates.windows.0.bounced', 1)
                    ->where('rates.windows.0.bounce_rate', 1)
                    ->where('rates.windows.0.complaint_rate', 0)
                    ->where('rates.windows.1.sent', 110)
                    ->where('rates.windows.2.sent', 120)
                    ->where('rates.windows.2.complained', 0)));
    }

    public function test_rates_are_cached_per_client_for_sixty_seconds(): void
    {
        $user = User::factory()->create();
        $this->seedMails($user, 10, 0, 0, now()->subHour());
        $assertSent = fn (int $sent) => $this->actingAs($user)
            ->get(route('reputation.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('rates.windows.0.sent', $sent)));

        $assertSent(10);
        $this->assertTrue(Cache::has("reputation-rates:{$user->client_id}"));

        $this->seedMails($user, 5, 0, 0, now()->subHour());
        $assertSent(10);

        $this->travel(61)->seconds();
        $assertSent(15);
    }

    public function test_thresholds_are_passed_to_the_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('reputation.show'))
            ->assertInertia(fn (Assert $page) => $page->where('thresholds', [
                'bounce_review' => 5, 'bounce_pause' => 10, 'complaint_review' => 0.1, 'complaint_pause' => 0.5,
            ]));
    }

    public function test_empty_client_gets_null_rates(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('reputation.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sesCredential', null)
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('rates.warn', false)
                    ->where('rates.windows.0.sent', 0)
                    ->where('rates.windows.0.bounce_rate', null)
                    ->where('rates.windows.0.bounce_level', null)
                    ->where('rates.windows.2.complaint_rate', null)));
    }

    public function test_complaint_rate_of_one_tenth_percent_in_24h_sets_warn(): void
    {
        $user = User::factory()->create();
        $this->seedMails($user, 1000, 0, 1, now()->subHour());

        $this->actingAs($user)
            ->get(route('reputation.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('rates.warn', true)
                    ->where('rates.windows.0.complaint_rate', 0.1)));
    }

    public function test_rate_that_rounds_up_to_the_threshold_keeps_level_ok_and_no_warn(): void
    {
        $user = User::factory()->create();
        $this->seedMails($user, 2010, 0, 2, now()->subHour());

        $this->actingAs($user)
            ->get(route('reputation.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('rates.windows.0.complaint_rate', 0.1)
                    ->where('rates.windows.0.complaint_level', 'ok')
                    ->where('rates.windows.0.bounce_level', 'ok')
                    ->where('rates.warn', false)));
    }

    public function test_rate_levels_follow_the_unrounded_ratio(): void
    {
        $user = User::factory()->create();
        $this->seedMails($user, 1000, 100, 1, now()->subHour());

        $this->actingAs($user)
            ->get(route('reputation.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('rates.windows.0.bounce_level', 'pause')
                    ->where('rates.windows.0.complaint_level', 'review')
                    ->where('rates.windows.0.bounce_rate', 10)
                    ->where('rates.warn', true)));
    }

    public function test_probation_account_card_data(): void
    {
        Carbon::setTestNow('2026-09-22 12:00:00');
        $user = User::factory()->create();
        EmailCredential::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'type' => 'ses',
            'enforcement_status' => 'PROBATION',
            'sending_enabled' => true,
            'max_24_hour_send' => 50000,
            'max_send_rate' => 14,
            'sent_last_24_hours' => 1200,
            'quota_fetched_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('reputation.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sesCredential.enforcement_status', 'PROBATION')
                ->where('sesCredential.sending_enabled', true)
                ->where('sesCredential.max_24_hour_send', 50000)
                ->where('sesCredential.max_send_rate', 14)
                ->where('sesCredential.sent_last_24_hours', 1200)
                ->where('sesCredential.quota_fetched_at', now()->toIso8601String())
                ->where('sesAccountStatus.enforcement_status', 'PROBATION'));
    }

    public function test_navigation_has_reputation_link_after_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('reputation.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('navigation.main.1.key', 'reputation')
                ->where('navigation.main.1.href', '/reputation')
                ->where('navigation.main.1.group', 'Overview'));
    }

    private function seedMails(User $user, int $sent, int $bounced, int $complained, CarbonInterface $sentAt): void
    {
        $contact = Contact::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);

        $rows = ContactMail::factory()->count($sent)->make([
            'contact_id' => $contact->id,
            'client_id' => $user->client_id,
            'mailable_id' => 1,
            'sent_at' => $sentAt,
        ])->map(fn (ContactMail $mail, int $index): array => array_merge($mail->getAttributes(), [
            'bounced_at' => $index < $bounced ? $sentAt : null,
            'complained_at' => $index < $complained ? $sentAt : null,
            'mailable_id' => $index + 1,
            'uuid' => (string) Str::uuid(),
        ]));

        foreach ($rows->chunk(500) as $chunk) {
            ContactMail::withoutGlobalScopes()->insert($chunk->values()->all());
        }
    }
}
