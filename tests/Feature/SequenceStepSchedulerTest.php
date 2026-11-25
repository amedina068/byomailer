<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\SequenceStepScheduler;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class SequenceStepSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_step_uses_add_hours_for_hours_delay_type(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));

        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 6,
            'delay_type' => 'hours',
            'status' => 'live',
        ]);
        $contact = Contact::factory()->for($user)->create();
        $contact->sequences()->attach($sequence->id);

        $scheduler = app(SequenceStepScheduler::class);
        $scheduler->scheduleStep($contact, $sequence, $step);

        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'scheduled_at' => '2026-01-10 15:00:00',
        ]);

        Carbon::setTestNow();
    }

    public function test_schedule_step_uses_add_days_for_days_delay_type(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));

        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 3,
            'delay_type' => 'days',
            'status' => 'live',
        ]);
        $contact = Contact::factory()->for($user)->create();
        $contact->sequences()->attach($sequence->id);

        $scheduler = app(SequenceStepScheduler::class);
        $scheduler->scheduleStep($contact, $sequence, $step);

        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'scheduled_at' => '2026-01-13 09:00:00',
        ]);

        Carbon::setTestNow();
    }

    public function test_schedule_initial_steps_picks_first_step_with_mixed_delay_types(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));

        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);

        $hourStep = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 6,
            'delay_type' => 'hours',
            'status' => 'live',
        ]);
        $dayStep = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 1,
            'delay_type' => 'days',
            'status' => 'live',
        ]);

        $contact = Contact::factory()->for($user)->create();

        $scheduler = app(SequenceStepScheduler::class);
        $scheduler->scheduleInitialSteps($contact, [$sequence->id]);

        // 6 hours < 1 day (24 hours), so the 6-hour step should be scheduled first
        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $hourStep->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_schedule_next_step_with_hours_finds_correct_next(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));

        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);

        $step1 = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 6,
            'delay_type' => 'hours',
            'status' => 'live',
        ]);
        $step2 = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 1,
            'delay_type' => 'days',
            'status' => 'live',
        ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->sequences()->attach($sequence->id);

        $contactMail = ContactMail::factory()
            ->for($contact)
            ->forSequenceStep($step1)
            ->state([
                'status' => 'sent',
                'sent_at' => now()->subHour(),
                'delivered_at' => now()->subMinutes(30),
            ])
            ->create();

        $scheduler = app(SequenceStepScheduler::class);
        $scheduler->scheduleNextStep($contactMail);

        // Next step after 6 hours should be 1 day (24 hours)
        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step2->id,
            'status' => 'pending',
        ]);

        Carbon::setTestNow();
    }

    public function test_scheduler_skips_a_sunset_contact(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step1 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'status' => 'live']);
        $step2 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1, 'status' => 'live']);

        $contact = Contact::factory()->for($user)->create(['sunset_at' => now()]);
        $contact->sequences()->attach($sequence->id);

        $sentMail = ContactMail::factory()
            ->for($contact)
            ->forSequenceStep($step1)
            ->state(['status' => 'sent', 'sent_at' => now()->subHour()])
            ->create();

        $newContact = Contact::factory()->for($user)->create(['sunset_at' => now()]);

        $scheduler = app(SequenceStepScheduler::class);
        $scheduler->scheduleNextStep($sentMail);
        $scheduler->scheduleStep($contact, $sequence, $step2);
        $scheduler->scheduleInitialSteps($newContact, [$sequence->id]);

        $this->assertSame(1, ContactMail::query()->where('contact_id', $contact->id)->count());
        $this->assertSame(0, ContactMail::query()->where('contact_id', $newContact->id)->count());
    }

    public function test_reschedule_after_step_deletion_skips_a_sunset_contact(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $deletedStep = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'status' => 'live']);
        $nextStep = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1, 'status' => 'live']);

        $active = Contact::factory()->for($user)->create();
        $sunset = Contact::factory()->for($user)->create(['sunset_at' => now()]);

        foreach ([$active, $sunset] as $contact) {
            $contact->sequences()->attach($sequence->id);
            ContactMail::factory()
                ->for($contact)
                ->forSequenceStep($deletedStep)
                ->state(['status' => 'sent', 'sent_at' => now()->subHour(), 'delivered_at' => now()->subMinutes(30)])
                ->create();
        }

        $this->actingAs($user);
        app(SequenceStepScheduler::class)->rescheduleAfterStepDeletion($deletedStep, $nextStep);

        $scheduled = ContactMail::query()
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $nextStep->id)
            ->pluck('contact_id')
            ->all();

        $this->assertSame([$active->id], $scheduled);
    }

    public function test_contact_remains_in_sequence_when_last_step_is_completed(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);

        $contact = Contact::factory()->for($user)->create();
        $contact->sequences()->attach($sequence->id);

        $contactMail = ContactMail::factory()
            ->for($contact)
            ->forSequenceStep($step)
            ->state([
                'status' => 'delivered',
                'sent_at' => now()->subHour(),
                'delivered_at' => now()->subMinutes(30),
            ])
            ->create();

        $scheduler = app(SequenceStepScheduler::class);
        $scheduler->scheduleNextStep($contactMail);

        $this->assertTrue($contact->sequences()->where('sequences.id', $sequence->id)->exists());
    }

    public function test_completing_last_step_marks_contact_completed_on_pivot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00'));

        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);

        $contact = Contact::factory()->for($user)->create();
        $contact->sequences()->attach($sequence->id);

        $contactMail = ContactMail::factory()
            ->for($contact)
            ->forSequenceStep($step)
            ->state([
                'status' => 'delivered',
                'sent_at' => now()->subHour(),
                'delivered_at' => now()->subMinutes(30),
            ])
            ->create();

        $scheduler = app(SequenceStepScheduler::class);
        $scheduler->scheduleNextStep($contactMail);

        $this->assertDatabaseHas('contact_sequence', [
            'contact_id' => $contact->id,
            'sequence_id' => $sequence->id,
            'completed_at' => '2026-06-25 12:00:00',
        ]);

        Carbon::setTestNow();
    }

    public function test_deleting_step_finds_next_with_mixed_delay_types(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));

        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);

        $step1 = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 12,
            'delay_type' => 'hours',
            'status' => 'live',
        ]);
        $step2 = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 1,
            'delay_type' => 'days',
            'status' => 'live',
        ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->sequences()->attach($sequence->id);

        ContactMail::factory()
            ->for($contact)
            ->forSequenceStep($step1)
            ->state([
                'status' => 'sent',
                'sent_at' => now()->subHour(),
                'delivered_at' => now()->subMinutes(30),
            ])
            ->create();

        // Deleting the 12-hour step should reschedule to the 1-day step
        $this->actingAs($user)->delete(route('sequences.steps.destroy', [
            'sequence' => $sequence,
            'sequenceStep' => $step1,
        ]));

        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step2->id,
            'status' => 'pending',
        ]);

        Carbon::setTestNow();
    }

    public function test_it_adds_default_utms_to_html_links(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Welcome Series']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'status' => 'live',
        ]);
        $step->load(['sequence', 'message']);
        $subject = $step->message->subject;

        $html = '<p><a href="https://example.com/guide">Guide</a></p>';

        $updated = SequenceStepScheduler::addDefaultUtmParametersToHtml($html, $step);

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($updated);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        $href = $document->getElementsByTagName('a')->item(0)?->getAttribute('href');

        $this->assertNotNull($href);

        $parts = parse_url($href);
        parse_str($parts['query'] ?? '', $query);

        $this->assertSame('mail', $query['utm_medium'] ?? null);
        $this->assertSame(Str::slug($subject), $query['utm_source'] ?? null);
        $this->assertSame(Str::slug($sequence->name), $query['utm_campaign'] ?? null);
    }

    public function test_it_does_not_override_existing_utms_but_fills_in_missing_ones(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Launch Campaign']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'status' => 'live',
        ]);
        $step->load(['sequence', 'message']);

        // utm_source and utm_medium are set; utm_campaign is missing.
        $html = '<a href="https://example.com?utm_source=custom&utm_medium=email">Link</a>';

        $updated = SequenceStepScheduler::addDefaultUtmParametersToHtml($html, $step);

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($updated);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        $href = $document->getElementsByTagName('a')->item(0)?->getAttribute('href');

        $parts = parse_url($href);
        parse_str($parts['query'] ?? '', $query);

        // Existing values are preserved.
        $this->assertSame('custom', $query['utm_source'] ?? null);
        $this->assertSame('email', $query['utm_medium'] ?? null);
        // Missing key is filled with the default.
        $this->assertSame(Str::slug($sequence->name), $query['utm_campaign'] ?? null);
    }

    public function test_it_updates_plain_text_links(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Nurture']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'status' => 'live',
        ]);
        $step->load(['sequence', 'message']);

        $text = 'Check https://example.com/demo for more info.';

        $updated = SequenceStepScheduler::addDefaultUtmParametersToText($text, $step);

        $this->assertStringContainsString('utm_medium=mail', $updated);
        $this->assertStringContainsString(Str::slug($sequence->name), $updated);
    }

    public function test_it_does_not_add_utms_to_template_variable_hrefs(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Welcome']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);
        $step->load(['sequence', 'message']);

        $html = '<a href="{{unsubscribe_url}}">Unsubscribe</a>';

        $updated = SequenceStepScheduler::addDefaultUtmParametersToHtml($html, $step);

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($updated);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        $href = $document->getElementsByTagName('a')->item(0)?->getAttribute('href');

        $this->assertSame('{{unsubscribe_url}}', $href);
    }

    public function test_it_does_not_corrupt_urls_with_trailing_punctuation(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Nurture']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);
        $step->load(['sequence', 'message']);

        $text = 'Visit https://example.com/page. More info at https://example.com/faq!';

        $updated = SequenceStepScheduler::addDefaultUtmParametersToText($text, $step);

        // Trailing punctuation must not end up inside the UTM-appended URL.
        $this->assertStringContainsString('utm_medium=mail', $updated);
        $this->assertStringContainsString('. More info', $updated);
        $this->assertTrue(str_ends_with($updated, '!'));
    }
}
