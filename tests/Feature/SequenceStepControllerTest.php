<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SequenceStepControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_renders_step_page(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $response = $this->actingAs($user)->get(route('sequences.steps.show', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('sequences/steps/Show')
            ->has('sequence')
            ->has('step')
            ->has('stepIndex')
            ->has('emailIdentities')
            ->has('stepStats')
        );
    }

    public function test_show_includes_step_filters_and_filter_options(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create([
            'filters' => [['connector' => 'and', 'category' => 'lists', 'mode' => 'any', 'values' => [$list->id]]],
        ]);

        $this->actingAs($user)->get(route('sequences.steps.show', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('step.filters.0.category', 'lists')
                ->where('step.filters.0.values', [$list->id])
                ->where('lists.0.id', $list->id)
                ->where('tags.0.id', $tag->id)
                ->has('topics')
                ->has('contactAttributes')
                ->has('filterNewsletters')
                ->has('filterCountries')
                ->has('filterTimezones')
            );
    }

    public function test_show_returns_empty_filters_for_a_step_without_filters(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create(['filters' => null]);

        $this->actingAs($user)->get(route('sequences.steps.show', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]))
            ->assertInertia(fn ($page) => $page->where('step.filters', []));
    }

    public function test_show_returns_correct_step_index(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step1 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'delay_type' => 'days']);
        $step2 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 2, 'delay_type' => 'days']);

        $response = $this->actingAs($user)->get(route('sequences.steps.show', [
            'sequence' => $sequence,
            'sequenceStep' => $step2,
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stepIndex', 1)
            ->where('step.id', $step2->id)
        );
    }

    public function test_show_returns_404_for_step_not_belonging_to_sequence(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $otherSequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($otherSequence)->create();

        $response = $this->actingAs($user)->get(route('sequences.steps.show', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));

        $response->assertNotFound();
    }

    public function test_show_returns_404_for_another_users_sequence(): void
    {
        $user = User::factory()->create();
        $otherSequence = Sequence::factory()->create();
        $step = SequenceStep::factory()->for($otherSequence)->create();

        $response = $this->actingAs($user)->get(route('sequences.steps.show', [
            'sequence' => $otherSequence,
            'sequenceStep' => $step,
        ]));

        $response->assertNotFound();
    }

    public function test_show_includes_step_stats(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);

        $contact = Contact::factory()->for($user)->create();
        ContactMail::factory()
            ->for($contact)
            ->forSequenceStep($step)
            ->state(['status' => 'sent', 'sent_at' => now()])
            ->create();

        $response = $this->actingAs($user)->get(route('sequences.steps.show', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stepStats.contacts', 1)
        );
    }

    public function test_deleting_step_removes_step_and_message(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);
        $messageId = $step->message->id;

        $this->actingAs($user)->delete(route('sequences.steps.destroy', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));

        $this->assertDatabaseMissing('sequence_steps', ['id' => $step->id]);
        $this->assertDatabaseMissing('messages', ['id' => $messageId]);
    }

    public function test_deleting_step_removes_pending_contact_mails_for_that_step(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);
        $contact = Contact::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->pending()
            ->for($contact)
            ->forSequenceStep($step)
            ->create();

        $this->actingAs($user)->delete(route('sequences.steps.destroy', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));

        $this->assertDatabaseMissing('contact_mails', ['id' => $contactMail->id]);
    }

    public function test_deleting_step_does_not_remove_sent_or_delivered_contact_mails(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);
        $contact = Contact::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->forSequenceStep($step)
            ->state([
                'status' => 'sent',
                'sent_at' => now()->subHour(),
                'delivered_at' => now()->subMinutes(30),
            ])
            ->create();

        $this->actingAs($user)->delete(route('sequences.steps.destroy', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));

        $this->assertDatabaseHas('contact_mails', ['id' => $contactMail->id]);
    }

    public function test_deleting_step_reschedules_next_step_for_contacts_who_skipped_it(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $deletedStep = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1, 'status' => 'live']);
        $nextStep = SequenceStep::factory()->for($sequence)->create(['delay_days' => 3, 'status' => 'live']);

        $contact = Contact::factory()->for($user)->create();
        $contact->sequences()->attach($sequence->id);
        ContactMail::factory()->for($contact)->forSequenceStep($deletedStep)->skipped()->create();

        $this->actingAs($user)->delete(route('sequences.steps.destroy', [
            'sequence' => $sequence,
            'sequenceStep' => $deletedStep,
        ]));

        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $nextStep->id,
            'status' => 'pending',
        ]);
    }

    public function test_deleting_step_reschedules_next_step_for_contacts_with_delivered_mail(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));

        $user = User::factory()->create();

        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $deletedStep = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 1,
            'status' => 'live',
        ]);
        $nextStep = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 3,
            'status' => 'live',
        ]);

        $contactNeedingNext = Contact::factory()->for($user)->create();
        $contactNeedingNext->sequences()->attach($sequence->id);
        ContactMail::factory()
            ->for($contactNeedingNext)
            ->forSequenceStep($deletedStep)
            ->state([
                'status' => 'sent',
                'sent_at' => now()->subHours(12),
                'delivered_at' => now()->subHours(10),
            ])
            ->create();

        $contactAlreadyScheduled = Contact::factory()->for($user)->create();
        $contactAlreadyScheduled->sequences()->attach($sequence->id);
        ContactMail::factory()
            ->for($contactAlreadyScheduled)
            ->forSequenceStep($deletedStep)
            ->state([
                'status' => 'sent',
                'sent_at' => now()->subHours(12),
                'delivered_at' => now()->subHours(10),
            ])
            ->create();
        ContactMail::factory()
            ->pending()
            ->for($contactAlreadyScheduled)
            ->forSequenceStep($nextStep)
            ->create();

        $existingNextStepCount = ContactMail::query()
            ->where('contact_id', $contactAlreadyScheduled->id)
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $nextStep->id)
            ->count();

        $this->actingAs($user)->delete(route('sequences.steps.destroy', [
            'sequence' => $sequence,
            'sequenceStep' => $deletedStep,
        ]));

        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contactNeedingNext->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $nextStep->id,
            'status' => 'pending',
        ]);

        $this->assertSame(
            $existingNextStepCount,
            ContactMail::query()
                ->where('contact_id', $contactAlreadyScheduled->id)
                ->where('mailable_type', SequenceStep::class)
                ->where('mailable_id', $nextStep->id)
                ->count()
        );

        Carbon::setTestNow();
    }

    public function test_user_cannot_delete_step_from_another_users_sequence(): void
    {
        $user = User::factory()->create();
        $otherSequence = Sequence::factory()->create();
        $step = SequenceStep::factory()->for($otherSequence)->create();

        $response = $this->actingAs($user)->delete(route('sequences.steps.destroy', [
            'sequence' => $otherSequence,
            'sequenceStep' => $step,
        ]));

        $response->assertNotFound();
        $this->assertDatabaseHas('sequence_steps', ['id' => $step->id]);
    }

    public function test_deleting_step_redirects_to_sequence_show_page(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);

        $response = $this->actingAs($user)->delete(route('sequences.steps.destroy', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));

        $response->assertRedirect(route('sequences.show', $sequence));
    }

    public function test_store_creates_step_with_days_delay_type(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('sequences.steps.store', $sequence), [
            'delay_days' => 3,
            'delay_type' => 'days',
            'subject' => 'Welcome!',
            'content' => '<p>Hello</p>',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('sequence_steps', [
            'sequence_id' => $sequence->id,
            'delay_days' => 3,
            'delay_type' => 'days',
        ]);
    }

    public function test_store_creates_step_with_hours_delay_type(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('sequences.steps.store', $sequence), [
            'delay_days' => 6,
            'delay_type' => 'hours',
            'subject' => 'Quick follow-up',
            'content' => '<p>Hey</p>',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('sequence_steps', [
            'sequence_id' => $sequence->id,
            'delay_days' => 6,
            'delay_type' => 'hours',
        ]);
    }

    public function test_store_sets_message_email_identity_details(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $this->actingAs($user)->post(route('sequences.steps.store', $sequence), [
            'delay_days' => 1,
            'delay_type' => 'days',
            'subject' => 'Identity check',
            'content' => '<p>Body</p>',
            'email_identity_id' => $identity->id,
        ]);

        $step = $sequence->steps()->latest('id')->first();

        $this->assertNotNull($step);
        $this->assertSame($identity->id, $step->message?->email_identity_id);
        $this->assertSame($identity->email, $step->message?->from_email);
        $this->assertSame($identity->name, $step->message?->from_name);
    }

    public function test_store_rejects_email_identity_from_another_user(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $otherIdentity = EmailIdentity::factory()->create();

        $response = $this->actingAs($user)->post(route('sequences.steps.store', $sequence), [
            'delay_days' => 2,
            'delay_type' => 'days',
            'subject' => 'Bad identity',
            'content' => '<p>Body</p>',
            'email_identity_id' => $otherIdentity->id,
        ]);

        $response->assertSessionHasErrors('email_identity_id');
    }

    public function test_store_rejects_invalid_delay_type(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('sequences.steps.store', $sequence), [
            'delay_days' => 3,
            'delay_type' => 'weeks',
            'subject' => 'Test',
            'content' => '<p>Test</p>',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertSessionHasErrors('delay_type');
    }

    public function test_store_rejects_missing_delay_type(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('sequences.steps.store', $sequence), [
            'delay_days' => 3,
            'subject' => 'Test',
            'content' => '<p>Test</p>',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertSessionHasErrors('delay_type');
    }

    public function test_update_changes_delay_type(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 2,
            'delay_type' => 'days',
        ]);
        $identity = EmailIdentity::factory()->for($user)->create();

        $response = $this->actingAs($user)->patch(route('sequences.steps.update', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]), [
            'delay_days' => 12,
            'delay_type' => 'hours',
            'subject' => $step->message->subject,
            'content' => $step->message->html_content,
            'email_identity_id' => $identity->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('sequence_steps', [
            'id' => $step->id,
            'delay_days' => 12,
            'delay_type' => 'hours',
        ]);
    }

    public function test_store_allows_up_to_8760_for_hours(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('sequences.steps.store', $sequence), [
            'delay_days' => 8760,
            'delay_type' => 'hours',
            'subject' => 'Max hours',
            'content' => '<p>Test</p>',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertSessionDoesntHaveErrors('delay_days');
    }

    public function test_store_rejects_over_365_for_days(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('sequences.steps.store', $sequence), [
            'delay_days' => 366,
            'delay_type' => 'days',
            'subject' => 'Too many days',
            'content' => '<p>Test</p>',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertSessionHasErrors('delay_days');
    }

    public function test_update_cannot_change_a_step_of_another_tenant_through_an_own_sequence(): void
    {
        $attacker = User::factory()->create();
        $attackerSequence = Sequence::factory()->for($attacker)->create();
        $attackerIdentity = EmailIdentity::factory()->for($attacker)->create();
        $victimStep = SequenceStep::factory()->for(Sequence::factory())->create([
            'delay_days' => 3,
            'delay_type' => 'days',
        ]);
        $victimSubject = $victimStep->message()->withoutGlobalScopes()->value('subject');

        $response = $this->actingAs($attacker)->patch(route('sequences.steps.update', [
            'sequence' => $attackerSequence,
            'sequenceStep' => $victimStep,
        ]), [
            'delay_days' => 8000,
            'delay_type' => 'hours',
            'subject' => 'changed by another tenant',
            'content' => '<p>changed</p>',
            'email_identity_id' => $attackerIdentity->id,
        ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('sequence_steps', [
            'id' => $victimStep->id,
            'delay_days' => 3,
            'delay_type' => 'days',
        ]);
        $this->assertSame($victimSubject, $victimStep->message()->withoutGlobalScopes()->value('subject'));
    }
}
