<?php

namespace Tests\Feature;

use App\Enums\AbVariant;
use App\Enums\NewsletterStatus;
use App\Enums\SequenceStatus;
use App\Jobs\DispatchNewsletterBatchJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\NewsletterScheduleService;
use App\Services\SendableContentCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The unsubscribe link and the postal address are the responsibility of the
 * sender. BYOMailer warns about them, and it never blocks a send for them.
 */
class SendableContentComplianceTest extends TestCase
{
    use RefreshDatabase;

    private const WITH_LINK = '<p>Hello</p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>';

    private function message(User $user, array $attributes = []): Message
    {
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update(array_merge([
            'subject' => 'Monthly update',
            'html_content' => self::WITH_LINK,
            'text_content' => 'Hello',
        ], $attributes));

        return $newsletter->message->fresh();
    }

    /**
     * @return array{0: User, 1: Newsletter, 2: EmailIdentity}
     */
    private function draftWithoutLinkOrAddress(): array
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $user->client->update(['postal_address' => null]);
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->withListFilters([$list->id])->create(['status' => 'draft']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'subject' => 'Monthly update',
            'html_content' => '<p>Hello</p>',
            'text_content' => 'Hello',
        ]);

        return [$user, $newsletter, $identity];
    }

    public function test_content_with_an_unsubscribe_link_and_an_address_has_no_problems_and_no_warnings(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $message = $this->message($user);
        $check = app(SendableContentCheck::class);

        $this->assertSame([], $check->problems($message));
        $this->assertSame([], $check->warnings($message));
    }

    public function test_content_without_an_unsubscribe_link_is_a_warning_and_not_a_problem(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $message = $this->message($user, ['html_content' => '<p>Hello</p>']);
        $check = app(SendableContentCheck::class);

        $this->assertSame([], $check->problems($message));
        $this->assertSame(SendableContentCheck::UNSUBSCRIBE_WARNING, $check->warnings($message)['unsubscribe'] ?? null);
    }

    public function test_variant_b_without_an_unsubscribe_link_uses_its_own_warning_key(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $message = $this->message($user, ['html_content' => '<p>Hello</p>']);

        $warnings = app(SendableContentCheck::class)->warnings($message, AbVariant::B);

        $this->assertSame(['unsubscribe_b'], array_keys($warnings));
        $this->assertSame(SendableContentCheck::UNSUBSCRIBE_B_WARNING, $warnings['unsubscribe_b']);
    }

    public function test_a_text_only_message_can_carry_the_unsubscribe_link(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $message = $this->message($user, [
            'html_content' => '',
            'text_content' => "Hello\n\nUnsubscribe: {{ unsubscribe_url }}",
        ]);

        $this->assertArrayNotHasKey('unsubscribe', app(SendableContentCheck::class)->warnings($message));
    }

    public function test_a_client_without_a_postal_address_is_a_warning_and_not_a_problem(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $user->client->update(['postal_address' => '  ']);
        $message = $this->message($user);
        $check = app(SendableContentCheck::class);

        $this->assertSame([], $check->problems($message));
        $this->assertSame(['postal_address' => SendableContentCheck::POSTAL_ADDRESS_WARNING], $check->warnings($message));
    }

    public function test_the_send_check_returns_the_warnings_and_no_errors(): void
    {
        [$user, $newsletter] = $this->draftWithoutLinkOrAddress();

        $check = app(NewsletterScheduleService::class)->sendCheck($user, $newsletter->fresh(['message', 'messageB']));

        $this->assertSame([], $check['errors']);
        $this->assertSame([
            'unsubscribe' => SendableContentCheck::UNSUBSCRIBE_WARNING,
            'postal_address' => SendableContentCheck::POSTAL_ADDRESS_WARNING,
        ], $check['warnings']);
    }

    public function test_web_send_now_without_an_unsubscribe_link_or_a_postal_address_queues_the_newsletter(): void
    {
        Bus::fake();
        [$user, $newsletter, $identity] = $this->draftWithoutLinkOrAddress();

        $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('newsletters.show', $newsletter));

        $this->assertSame(NewsletterStatus::Queued, $newsletter->fresh()->status);
        Bus::assertDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_web_schedule_without_an_unsubscribe_link_or_a_postal_address_schedules_the_newsletter(): void
    {
        [$user, $newsletter, $identity] = $this->draftWithoutLinkOrAddress();

        $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'schedule',
            'scheduled_for' => now()->addDay()->format('Y-m-d\TH:i'),
            'email_identity_id' => $identity->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(NewsletterStatus::Scheduled, $newsletter->fresh()->status);
    }

    public function test_api_live_update_without_an_unsubscribe_link_or_a_postal_address_queues_the_newsletter(): void
    {
        Bus::fake([DispatchNewsletterBatchJob::class]);
        [$user, $newsletter, $identity] = $this->draftWithoutLinkOrAddress();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'subject' => 'Live subject',
            'html_content' => '<p>Live content without a link</p>',
            'status' => 'live',
            'scheduled_at' => now()->toDateTimeString(),
            'email_identity_id' => $identity->id,
        ])->assertOk()->assertJsonPath('data.status', 'queued');

        Bus::assertDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_sequence_step_goes_live_without_an_unsubscribe_link_or_a_postal_address(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $user->client->update(['postal_address' => null]);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'draft']);
        $step->message()->update(['html_content' => '<p>Welcome</p>', 'text_content' => 'Welcome']);

        $this->actingAs($user)->patch(
            route('sequences.steps.status.update', [$sequence, $step]),
            ['status' => 'live'],
        )->assertSessionHasNoErrors();

        $this->assertSame(SequenceStatus::Live, $step->fresh()->status);
    }

    public function test_the_postal_address_variable_resolves_to_the_client_address(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $user->client->update(['postal_address' => '1 Main St, Springfield']);
        $message = $this->message($user, ['html_content' => self::WITH_LINK.'<p>{{ postal_address }}</p>']);
        $contact = Contact::factory()->for($user)->create();
        $contactMail = ContactMail::factory()->for($contact)->forNewsletter($message->messageable)->create();

        $data = $message->templateData($contact, $contactMail);

        $this->assertSame('1 Main St, Springfield', $data['postal_address']);
    }

    public function test_the_profile_page_saves_the_postal_address(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => 'UTC',
            'postal_address' => '42 Harbor Road, Portland, OR 97201',
        ])->assertSessionHasNoErrors();

        $this->assertSame('42 Harbor Road, Portland, OR 97201', $user->client->fresh()->postal_address);
    }

    public function test_the_postal_address_stays_optional_in_the_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => 'UTC',
            'postal_address' => '',
        ])->assertSessionHasNoErrors();

        $this->assertNull($user->client->fresh()->postal_address);
    }

    public function test_pages_share_whether_the_account_has_a_postal_address(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('newsletters.index'))
            ->assertInertia(fn (Assert $page) => $page->where('hasPostalAddress', true));

        $user->client->update(['postal_address' => " \n "]);

        $this->actingAs($user)->get(route('newsletters.index'))
            ->assertInertia(fn (Assert $page) => $page->where('hasPostalAddress', false));
    }
}
