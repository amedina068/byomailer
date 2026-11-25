<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LinkClickPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_newsletter_show_defers_the_link_report_and_filters_recipients_by_link(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $ana = $this->mailFor($user, $newsletter, 'ana@example.com');
        $this->mailFor($user, $newsletter, 'ben@example.com');
        $this->click($ana, 'https://example.com/sale?utm_source=x', 'black-friday');

        $this->actingAs($user)
            ->get(route('newsletters.show', ['newsletter' => $newsletter->id, 'link' => 'name:black-friday']))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('linkClicks')
                ->where('recipientLinkFilter', 'name:black-friday')
                ->has('recipients.data', 1)
                ->where('recipients.data.0.email', 'ana@example.com')
                ->where('recipients.data.0.link_clicks_count', 1)
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('linkClicks.links.0.key', 'name:black-friday')
                    ->where('linkClicks.links.0.unique_contacts', 1)
                    ->where('linkClicks.delivered', 2)
                )
            );
    }

    public function test_newsletter_show_ignores_an_invalid_link_filter(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $this->mailFor($user, $newsletter, 'ana@example.com');

        $this->actingAs($user)
            ->get(route('newsletters.show', ['newsletter' => $newsletter->id, 'link' => 'black-friday']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('recipientLinkFilter', null)
                ->has('recipients.data', 1)
            );
    }

    public function test_step_contacts_defer_the_link_report(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $this->click($this->mailFor($user, $step, 'ana@example.com'), 'https://example.com/pricing?utm_source=x');

        $this->actingAs($user)
            ->get(route('sequences.steps.contacts.index', ['sequence' => $sequence->id, 'sequenceStep' => $step->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('linkClicks')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('linkClicks.links.0.key', 'url:https://example.com/pricing')
                )
            );
    }

    public function test_step_contacts_filter_by_link(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $ana = $this->mailFor($user, $step, 'ana@example.com');
        $this->mailFor($user, $step, 'ben@example.com');
        $this->click($ana, 'https://example.com/pricing?utm_source=x');

        $this->actingAs($user)
            ->get(route('sequences.steps.contacts.index', [
                'sequence' => $sequence->id,
                'sequenceStep' => $step->id,
                'link' => 'url:https://example.com/pricing',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('linkFilter', 'url:https://example.com/pricing')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.email', 'ana@example.com')
            );
    }

    public function test_step_contacts_with_a_link_filter_show_the_latest_click_on_that_link(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $ana = $this->mailFor($user, $step, 'ana@example.com');
        $this->click($ana, 'https://example.com/sale', 'black-friday', now()->subHour());
        $this->click($ana, 'https://example.com/blog', null, now());

        $this->actingAs($user)
            ->get(route('sequences.steps.contacts.index', [
                'sequence' => $sequence->id,
                'sequenceStep' => $step->id,
                'link' => 'name:black-friday',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('contacts.data.0.latest_click.url', 'https://example.com/sale')
            );
    }

    private function mailFor(User $user, Newsletter|SequenceStep $mailable, string $email): ContactMail
    {
        return ContactMail::factory()
            ->for(Contact::factory()->for($user)->state(['email' => $email]))
            ->for($mailable, 'mailable')
            ->create(['delivered_at' => now()]);
    }

    private function click(ContactMail $mail, string $link, ?string $linkName = null, mixed $clickedAt = null): void
    {
        ContactMailClick::factory()->create([
            'contact_mail_id' => $mail->id,
            'contact_id' => $mail->contact_id,
            'link' => $link,
            'link_name' => $linkName,
            'clicked_at' => $clickedAt ?? now(),
        ]);
    }
}
