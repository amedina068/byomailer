<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SequenceContactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_sequence_contacts_with_filters(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $steps = SequenceStep::factory()->count(2)->for($sequence)->create();

        $active = Contact::factory()->for($user)->create();
        $sequence->contacts()->syncWithoutDetaching([$active->id]);

        $unsubscribed = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now(),
        ]);
        $sequence->contacts()->syncWithoutDetaching([$unsubscribed->id]);
        ContactMail::factory()
            ->for($unsubscribed)
            ->forSequenceStep($steps->first())
            ->create([
                'status' => 'unsubscribed',
                'unsubscribed_at' => now(),
            ]);

        $clicked = Contact::factory()->for($user)->create();
        $sequence->contacts()->syncWithoutDetaching([$clicked->id]);
        $clickedMail = ContactMail::factory()
            ->for($clicked)
            ->forSequenceStep($steps->last())
            ->create([
                'status' => 'opened',
                'clicked_at' => now(),
            ]);
        ContactMailClick::create([
            'contact_mail_id' => $clickedMail->id,
            'contact_id' => $clicked->id,
            'link' => 'https://example.com/pricing',
            'clicked_at' => now(),
        ]);

        $this->actingAs($user);

        $this->get(route('sequences.contacts.index', $sequence))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sequences/Contacts')
                ->where('sequence.id', $sequence->id)
                ->where('stats.contacts', 3)
                ->where('stats.unsubscribed', 1)
                ->where('stats.clicked', 1)
                ->where('contacts.data', fn ($data) => in_array($active->id, collect($data)->pluck('id')->all(), true)));

        $this->get(route('sequences.contacts.index', [
            'sequence' => $sequence->id,
            'filter' => 'clicked',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sequences/Contacts')
                ->where('filter', 'clicked')
                ->where('contacts.data.0.id', $clicked->id)
                ->where('contacts.data.0.latest_click.url', 'https://example.com/pricing')
                ->where('contacts.total', 1));
    }

    public function test_completed_filter_lists_finished_contacts_and_drains_final_step(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $finished = Contact::factory()->for($user)->create(['name' => 'Done']);
        $sequence->contacts()->syncWithoutDetaching([$finished->id => ['completed_at' => now()]]);
        ContactMail::factory()->for($finished)->forSequenceStep($step)->create([
            'status' => 'delivered',
            'sent_at' => now()->subHour(),
            'delivered_at' => now()->subMinutes(30),
        ]);

        $active = Contact::factory()->for($user)->create(['name' => 'Active']);
        $sequence->contacts()->syncWithoutDetaching([$active->id]);
        ContactMail::factory()->for($active)->forSequenceStep($step)->create([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->actingAs($user);

        $this->get(route('sequences.contacts.index', [
            'sequence' => $sequence->id,
            'filter' => 'completed',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sequences/Contacts')
                ->where('filter', 'completed')
                ->where('stats.completed', 1)
                ->where('contacts.total', 1)
                ->where('contacts.data.0.id', $finished->id));

        // Finished contact no longer lingers in the final step.
        $this->get(route('sequences.steps.contacts.index', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step->id,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('contacts.total', 1)
                ->where('contacts.data.0.id', $active->id));
    }

    public function test_user_can_view_sequence_step_contacts_by_action(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $contact = Contact::factory()->for($user)->create();
        $sequence->contacts()->syncWithoutDetaching([$contact->id]);
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->forSequenceStep($step)
            ->create([
                'status' => 'opened',
                'clicked_at' => now(),
            ]);
        ContactMailClick::create([
            'contact_mail_id' => $contactMail->id,
            'contact_id' => $contact->id,
            'link' => 'https://example.com/demo',
            'clicked_at' => now(),
        ]);

        $this->actingAs($user);

        $this->get(route('sequences.steps.contacts.index', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step->id,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sequences/StepContacts')
                ->where('step.id', $step->id)
                ->where('stats.clicked', 1)
                ->where('contacts.data.0.id', $contact->id));

        $this->get(route('sequences.steps.contacts.index', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step->id,
            'filter' => 'clicked',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter', 'clicked')
                ->where('contacts.data.0.latest_click.url', 'https://example.com/demo')
                ->where('contacts.total', 1));
    }

    public function test_step_contacts_sorted_desc_by_send_date_with_status_and_send_date(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $oldest = Contact::factory()->for($user)->create(['name' => 'Alpha']);
        $sequence->contacts()->syncWithoutDetaching([$oldest->id]);
        ContactMail::factory()->for($oldest)->forSequenceStep($step)->create([
            'status' => 'delivered',
            'sent_at' => now()->subDays(3),
            'scheduled_at' => now()->subDays(3),
            'delivered_at' => now()->subDays(3),
        ]);

        $middle = Contact::factory()->for($user)->create(['name' => 'Bravo']);
        $sequence->contacts()->syncWithoutDetaching([$middle->id]);
        ContactMail::factory()->for($middle)->forSequenceStep($step)->create([
            'status' => 'opened',
            'sent_at' => now()->subDay(),
            'scheduled_at' => now()->subDay(),
            'opened_at' => now()->subDay(),
        ]);

        $newest = Contact::factory()->for($user)->create(['name' => 'Charlie']);
        $sequence->contacts()->syncWithoutDetaching([$newest->id]);
        ContactMail::factory()->for($newest)->forSequenceStep($step)->pending()->create([
            'scheduled_at' => now()->addHour(),
        ]);

        $this->actingAs($user);

        $this->get(route('sequences.steps.contacts.index', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step->id,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sequences/StepContacts')
                ->where('contacts.total', 3)
                ->where('contacts.data.0.id', $newest->id)
                ->where('contacts.data.0.send_status', 'pending')
                ->where('contacts.data.1.id', $middle->id)
                ->where('contacts.data.1.send_status', 'opened')
                ->where('contacts.data.2.id', $oldest->id)
                ->where('contacts.data.2.send_status', 'delivered')
                ->where('contacts.data.2.send_date', fn ($value) => is_string($value) && $value !== ''));
    }

    public function test_step_contacts_show_skipped_status_for_a_skipped_mail(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $contact = Contact::factory()->for($user)->create();
        $sequence->contacts()->syncWithoutDetaching([$contact->id]);
        ContactMail::factory()->for($contact)->forSequenceStep($step)->skipped()->create();

        $this->actingAs($user);

        $this->get(route('sequences.steps.contacts.index', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step->id,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('contacts.data.0.id', $contact->id)
                ->where('contacts.data.0.send_status', 'skipped'));
    }

    public function test_step_contacts_show_failed_status_and_reason_for_a_failed_mail(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $blacklisted = Contact::factory()->for($user)->create();
        $sequence->contacts()->syncWithoutDetaching([$blacklisted->id]);
        ContactMail::factory()->for($blacklisted)->forSequenceStep($step)->create([
            'status' => 'failed',
            'failure_reason' => 'blacklisted',
            'scheduled_at' => now()->subHour(),
            'sent_at' => null,
        ]);

        $renderingFailure = Contact::factory()->for($user)->create();
        $sequence->contacts()->syncWithoutDetaching([$renderingFailure->id]);
        ContactMail::factory()->for($renderingFailure)->forSequenceStep($step)->create([
            'status' => 'failed',
            'failure_reason' => 'Template rendering failed',
            'scheduled_at' => now()->subDays(2),
            'sent_at' => now()->subDays(2),
        ]);

        $this->actingAs($user);

        $this->get(route('sequences.steps.contacts.index', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step->id,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('contacts.data.0.id', $blacklisted->id)
                ->where('contacts.data.0.send_status', 'failed')
                ->where('contacts.data.0.step_failure_reason', 'blacklisted')
                ->where('contacts.data.1.id', $renderingFailure->id)
                ->where('contacts.data.1.send_status', 'failed')
                ->where('contacts.data.1.step_failure_reason', 'Template rendering failed'));
    }

    public function test_step_contacts_send_no_failure_reason_for_a_mail_that_did_not_fail(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $contact = Contact::factory()->for($user)->create();
        $sequence->contacts()->syncWithoutDetaching([$contact->id]);
        ContactMail::factory()->for($contact)->forSequenceStep($step)->pending()->create([
            'failure_reason' => 'ses_permission_missing',
            'scheduled_at' => now()->addHour(),
        ]);

        $this->actingAs($user);

        $this->get(route('sequences.steps.contacts.index', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step->id,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('contacts.data.0.send_status', 'pending')
                ->where('contacts.data.0.step_failure_reason', null));
    }

    public function test_contact_who_progressed_to_next_step_does_not_appear_in_previous_step(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step1 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'delay_type' => 'days']);
        $step2 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 3, 'delay_type' => 'days']);

        $atStep1 = Contact::factory()->for($user)->create(['name' => 'Alpha']);
        $sequence->contacts()->syncWithoutDetaching([$atStep1->id]);
        ContactMail::factory()->for($atStep1)->forSequenceStep($step1)->create(['status' => 'sent']);

        $progressedToStep2 = Contact::factory()->for($user)->create(['name' => 'Beta']);
        $sequence->contacts()->syncWithoutDetaching([$progressedToStep2->id]);
        ContactMail::factory()->for($progressedToStep2)->forSequenceStep($step1)->create(['status' => 'sent']);
        ContactMail::factory()->for($progressedToStep2)->forSequenceStep($step2)->create(['status' => 'pending']);

        $this->actingAs($user);

        $this->get(route('sequences.steps.contacts.index', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step1->id,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('contacts.total', 1)
                ->where('contacts.data.0.id', $atStep1->id));
    }
}
