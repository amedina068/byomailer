<?php

namespace Tests\Feature;

use App\Enums\IdentityStatus;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_shows_no_credentials_state(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('hasCredentials', false)
            ->where('hasVerifiedIdentity', false)
            ->where('sesQuota', null)
        );
    }

    public function test_dashboard_shows_no_identity_state_when_credentials_exist(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'type' => 'ses',
            'sent_last_24_hours' => 125,
            'max_24_hour_send' => 10000,
            'max_send_rate' => 14,
        ]);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('hasCredentials', true)
            ->where('hasVerifiedIdentity', false)
            ->where('sesQuota.sent_last_24_hours', 125)
            ->where('sesQuota.max_24_hour_send', 10000)
            ->where('sesQuota.max_send_rate', 14)
        );
    }

    public function test_dashboard_shows_getting_started_when_fully_onboarded(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create(['type' => 'ses']);
        EmailIdentity::factory()->for($user)->create([
            'identity_status' => IdentityStatus::Verified,
        ]);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('hasCredentials', true)
            ->where('hasVerifiedIdentity', true)
            ->where('pendingIdentityId', null)
        );
    }

    public function test_dashboard_requires_verified_identity_not_just_any_identity(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create(['type' => 'ses']);
        $pending = EmailIdentity::factory()->for($user)->create([
            'identity_status' => IdentityStatus::Pending,
        ]);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('hasCredentials', true)
            ->where('hasVerifiedIdentity', false)
            ->where('pendingIdentityId', $pending->id)
        );
    }

    public function test_dashboard_has_no_pending_identity_when_the_client_has_none(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create(['type' => 'ses']);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('hasVerifiedIdentity', false)
            ->where('pendingIdentityId', null)
        );
    }

    public function test_dashboard_includes_recent_activity(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Newsletter::factory()->count(5)->for($user)->create(['status' => 'sent', 'sent_at' => now()]);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'sent', 'sent_at' => now()]);
        $newsletter->message()->update(['subject' => 'Launch email']);

        $contacts = Contact::factory()->count(22)->for($user)->create();
        $contact = $contacts->last();

        ContactMail::factory()->for($contact)->for($newsletter, 'mailable')->state([
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
        ])->create();

        Sequence::factory()->count(4)->for($user)->create(['status' => 'live']);
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Launch Journey', 'status' => 'live']);
        $contact->syncSequences([$sequence->id], true);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->missing('latestNewsletters')
            ->missing('recentContacts')
            ->missing('recentSequences')
            ->loadDeferredProps(fn (Assert $page) => $page
                ->has('latestNewsletters', 5)
                ->where('latestNewsletters.0.metrics.unsubscribed', 1)
            )
            ->loadDeferredProps('secondary', fn (Assert $page) => $page
                ->has('recentContacts', 20)
                ->where('recentContacts.0.id', $contact->id)
                ->has('recentSequences', 5)
                ->where('recentSequences.0.name', 'Launch Journey')
                ->where('recentSequences.0.active_count', 1)
                ->where('recentSequences.0.completed_count', 0)
            )
        );
    }

    public function test_dashboard_includes_weekly_stats(): void
    {
        Carbon::setTestNow(Carbon::parse('Wednesday'));

        $user = User::factory()->create();
        $this->actingAs($user);

        $contact = Contact::factory()->for($user)->create();

        // This week: 2 sent
        ContactMail::factory()
            ->count(2)
            ->for($contact)
            ->sent()
            ->create();

        // 1 bounced
        ContactMail::factory()
            ->for($contact)
            ->bounced()
            ->create();

        // 1 unsubscribed
        ContactMail::factory()
            ->for($contact)
            ->unsubscribed()
            ->create();

        // 1 click this week
        $sentMail = ContactMail::factory()
            ->for($contact)
            ->sent()
            ->create();

        ContactMailClick::factory()->create([
            'contact_mail_id' => $sentMail->id,
            'contact_id' => $contact->id,
            'clicked_at' => now(),
        ]);

        // Old data (8 days ago) - should NOT count
        $lastWeek = Carbon::now()->subDays(8);
        ContactMail::factory()
            ->for($contact)
            ->state([
                'status' => 'sent',
                'sent_at' => $lastWeek,
            ])
            ->create();

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('weeklyStats')
            ->where('weeklyStats.sent', 5)
            ->where('weeklyStats.delivered', 1)
            ->where('weeklyStats.bounced', 1)
            ->where('weeklyStats.unsubscribed', 1)
            ->where('weeklyStats.clicked', 1)
        );

        Carbon::setTestNow();
    }

    public function test_dashboard_includes_contact_growth_chart(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-11 12:00:00')); // Wednesday

        $user = User::factory()->create();
        $this->actingAs($user);

        // 6 days ago (Thu Mar 5): 3 new contacts
        Contact::factory()->count(3)->for($user)->create([
            'created_at' => Carbon::parse('2026-03-05 10:00:00'),
        ]);

        // 1 day ago (Tue Mar 10): 2 new contacts, 1 unsub
        Contact::factory()->count(2)->for($user)->create([
            'created_at' => Carbon::parse('2026-03-10 10:00:00'),
        ]);
        Contact::factory()->for($user)->create([
            'created_at' => Carbon::parse('2026-03-10 08:00:00'),
            'unsubscribed_at' => Carbon::parse('2026-03-10 14:00:00'),
        ]);

        // Today (Wed Mar 11): 1 new contact
        Contact::factory()->for($user)->create([
            'created_at' => Carbon::parse('2026-03-11 09:00:00'),
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->missing('contactGrowth')
            ->loadDeferredProps(fn (Assert $page) => $page
                ->has('contactGrowth', 7)
                ->where('contactGrowth.0.day', 'Thu 5')
                ->where('contactGrowth.0.new', 3)
                ->where('contactGrowth.0.unsubscribed', 0)
                ->where('contactGrowth.0.net', 3)
                ->where('contactGrowth.5.day', 'Tue 10')
                ->where('contactGrowth.5.new', 3)
                ->where('contactGrowth.5.unsubscribed', 1)
                ->where('contactGrowth.5.net', 2)
                ->where('contactGrowth.6.day', 'Wed 11')
                ->where('contactGrowth.6.new', 1)
                ->where('contactGrowth.6.unsubscribed', 0)
                ->where('contactGrowth.6.net', 1)
            )
        );

        Carbon::setTestNow();
    }

    public function test_dashboard_contact_growth_always_has_seven_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-11 12:00:00'));

        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->missing('contactGrowth')
            ->loadDeferredProps(fn (Assert $page) => $page
                ->has('contactGrowth', 7)
                ->where('contactGrowth.0.day', 'Thu 5')
                ->where('contactGrowth.0.new', 0)
                ->where('contactGrowth.6.day', 'Wed 11')
                ->where('contactGrowth.6.new', 0)
            )
        );

        Carbon::setTestNow();
    }

    public function test_dashboard_includes_monthly_ses_cost(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

        $user = User::factory()->create();
        $this->actingAs($user);

        $contact = Contact::factory()->for($user)->create();

        // 5 emails sent this month
        ContactMail::factory()
            ->count(5)
            ->for($contact)
            ->sent()
            ->create(['sent_at' => Carbon::parse('2026-03-10')]);

        // 1 email sent last month - should NOT count
        ContactMail::factory()
            ->for($contact)
            ->sent()
            ->create(['sent_at' => Carbon::parse('2026-02-28')]);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('monthlySesCost.emails_sent', 5)
            ->where('monthlySesCost.estimated_cost', 0)
        );

        Carbon::setTestNow();
    }

    public function test_dashboard_monthly_ses_cost_calculates_correctly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

        $user = User::factory()->create();
        $this->actingAs($user);

        $contact = Contact::factory()->for($user)->create();

        // 50 emails sent this month => $0.005 => rounded to $0.01
        ContactMail::factory()
            ->count(50)
            ->for($contact)
            ->sent()
            ->create(['sent_at' => Carbon::parse('2026-03-10')]);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('monthlySesCost.emails_sent', 50)
            ->where('monthlySesCost.estimated_cost', 0.01)
        );

        Carbon::setTestNow();
    }

    public function test_dashboard_monthly_ses_cost_defaults_to_zero(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('monthlySesCost.emails_sent', 0)
            ->where('monthlySesCost.estimated_cost', 0)
        );
    }

    public function test_dashboard_weekly_stats_defaults_to_zero(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('weeklyStats.sent', 0)
            ->where('weeklyStats.delivered', 0)
            ->where('weeklyStats.bounced', 0)
            ->where('weeklyStats.unsubscribed', 0)
            ->where('weeklyStats.clicked', 0)
        );
    }

    public function test_the_owner_of_a_new_instance_sees_the_ses_setup_state(): void
    {
        config(['services.sqs_ses_events.queue_url' => null, 'services.sqs_ses_events.queue_arn' => null]);
        $owner = User::factory()->create(['is_owner' => true]);
        $this->actingAs($owner);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('hasCredentials', false)
            ->where('sesSetup.sandbox', null)
            ->where('sesSetup.eventsConfigured', false)
            ->where('sesSetup.canConfigureEvents', true)
        );
    }

    public function test_the_ses_setup_state_reports_the_sandbox_and_the_event_queue(): void
    {
        $this->configureEventQueue();
        $owner = User::factory()->create(['is_owner' => true]);
        EmailCredential::factory()->for($owner)->create(['type' => 'ses', 'production_access_enabled' => false]);
        $this->actingAs($owner);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('sesSetup.sandbox', true)
            ->where('sesSetup.eventsConfigured', true)
        );
    }

    public function test_the_event_queue_needs_both_the_url_and_the_arn(): void
    {
        $this->configureEventQueue();
        config(['services.sqs_ses_events.queue_arn' => null]);
        $this->actingAs(User::factory()->create(['is_owner' => true]));

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('sesSetup.eventsConfigured', false)
        );
    }

    public function test_the_ses_setup_state_reports_production_access(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->production()->create(['type' => 'ses']);
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('sesSetup.sandbox', false)
        );
    }

    public function test_a_user_who_is_not_the_owner_cannot_configure_the_event_queue(): void
    {
        config(['services.sqs_ses_events.queue_url' => null, 'services.sqs_ses_events.queue_arn' => null]);
        $this->actingAs(User::factory()->create(['is_owner' => false]));

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('sesSetup.eventsConfigured', false)
            ->where('sesSetup.canConfigureEvents', false)
        );
    }

    private function configureEventQueue(): void
    {
        config([
            'services.sqs_ses_events.queue_url' => 'https://sqs.us-east-1.amazonaws.com/111111111111/byomailer-ses-events',
            'services.sqs_ses_events.queue_arn' => 'arn:aws:sqs:us-east-1:111111111111:byomailer-ses-events',
        ]);
    }
}
