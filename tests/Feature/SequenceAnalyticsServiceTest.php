<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\SequenceAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenceAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private SequenceAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SequenceAnalyticsService;
    }

    public function test_step_stats_returns_empty_array_when_no_steps(): void
    {
        $result = $this->service->stepStats(collect());

        $this->assertSame([], $result);
    }

    public function test_step_stats_counts_contacts_with_no_progression(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step1 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'delay_type' => 'days']);
        $step2 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1, 'delay_type' => 'days']);

        $contact1 = Contact::factory()->for($user)->create();
        $contact2 = Contact::factory()->for($user)->create();

        ContactMail::factory()->sent()->for($contact1)->forSequenceStep($step1)->create();
        ContactMail::factory()->sent()->for($contact2)->forSequenceStep($step2)->create();

        $steps = $sequence->steps()->get();
        $result = $this->service->stepStats($steps);

        $this->assertSame(1, $result[$step1->id]['contacts']);
        $this->assertSame(1, $result[$step2->id]['contacts']);
    }

    public function test_step_stats_excludes_progressed_contacts_from_earlier_steps(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step1 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'delay_type' => 'days']);
        $step2 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1, 'delay_type' => 'days']);
        $step3 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 2, 'delay_type' => 'days']);

        $contact1 = Contact::factory()->for($user)->create();
        $contact2 = Contact::factory()->for($user)->create();
        $contact3 = Contact::factory()->for($user)->create();

        // contact1 is still at step 1
        ContactMail::factory()->sent()->for($contact1)->forSequenceStep($step1)->create();

        // contact2 progressed from step 1 to step 2
        ContactMail::factory()->sent()->for($contact2)->forSequenceStep($step1)->create();
        ContactMail::factory()->sent()->for($contact2)->forSequenceStep($step2)->create();

        // contact3 progressed all the way to step 3
        ContactMail::factory()->sent()->for($contact3)->forSequenceStep($step1)->create();
        ContactMail::factory()->sent()->for($contact3)->forSequenceStep($step2)->create();
        ContactMail::factory()->sent()->for($contact3)->forSequenceStep($step3)->create();

        $steps = $sequence->steps()->get();
        $result = $this->service->stepStats($steps);

        // Only contact1 is still at step 1
        $this->assertSame(1, $result[$step1->id]['contacts']);
        // Only contact2 is at step 2
        $this->assertSame(1, $result[$step2->id]['contacts']);
        // Only contact3 is at step 3
        $this->assertSame(1, $result[$step3->id]['contacts']);
    }

    public function test_completed_contact_is_excluded_from_final_step_count(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step1 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'delay_type' => 'days']);
        $step2 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1, 'delay_type' => 'days']);

        $finished = Contact::factory()->for($user)->create();
        $stillAtLastStep = Contact::factory()->for($user)->create();

        // Both reached the final step; only $finished has completed the sequence.
        ContactMail::factory()->sent()->for($finished)->forSequenceStep($step1)->create();
        ContactMail::factory()->delivered()->for($finished)->forSequenceStep($step2)->create();
        ContactMail::factory()->sent()->for($stillAtLastStep)->forSequenceStep($step2)->create();

        $finished->sequences()->attach($sequence->id, ['completed_at' => now()]);
        $stillAtLastStep->sequences()->attach($sequence->id);

        $result = $this->service->stepStats($sequence->steps()->get());

        // Final step only counts the contact who hasn't completed yet.
        $this->assertSame(1, $result[$step2->id]['contacts']);
        $this->assertSame(1, $this->service->statsForStep($step2)['contacts']);
    }

    public function test_sequence_stats_counts_completed_contacts(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'delay_type' => 'days']);

        $completed = Contact::factory()->for($user)->create();
        $active = Contact::factory()->for($user)->create();

        $completed->sequences()->attach($sequence->id, ['completed_at' => now()]);
        $active->sequences()->attach($sequence->id);

        $stats = $this->service->sequenceStats($sequence->loadCount('contacts')->load('steps'));

        $this->assertSame(2, $stats['contacts']);
        $this->assertSame(1, $stats['completed']);
    }

    public function test_step_stats_counts_unsubscribed_and_clicked_regardless_of_progression(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step1 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'delay_type' => 'days']);
        $step2 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1, 'delay_type' => 'days']);

        $contact = Contact::factory()->for($user)->create();

        // contact clicked step1 and then progressed to step2 where they unsubscribed
        ContactMail::factory()->clicked()->for($contact)->forSequenceStep($step1)->create();
        ContactMail::factory()->unsubscribed()->for($contact)->forSequenceStep($step2)->create();

        $steps = $sequence->steps()->get();
        $result = $this->service->stepStats($steps);

        // contact progressed to step2 so not counted in step1's contacts
        $this->assertSame(0, $result[$step1->id]['contacts']);
        $this->assertSame(1, $result[$step1->id]['clicked']);

        $this->assertSame(1, $result[$step2->id]['contacts']);
        $this->assertSame(1, $result[$step2->id]['unsubscribed']);
    }

    public function test_stats_for_step_excludes_contacts_progressed_to_later_steps(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step1 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'delay_type' => 'days']);
        $step2 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1, 'delay_type' => 'days']);

        $stillAtStep1 = Contact::factory()->for($user)->create();
        $progressed = Contact::factory()->for($user)->create();

        ContactMail::factory()->sent()->for($stillAtStep1)->forSequenceStep($step1)->create();

        ContactMail::factory()->sent()->for($progressed)->forSequenceStep($step1)->create();
        ContactMail::factory()->sent()->for($progressed)->forSequenceStep($step2)->create();

        $result = $this->service->statsForStep($step1);

        $this->assertSame(1, $result['contacts']);
    }

    public function test_stats_for_step_matches_sequence_show_step_stats(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step1 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'delay_type' => 'days']);
        $step2 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1, 'delay_type' => 'days']);

        $c1 = Contact::factory()->for($user)->create();
        $c2 = Contact::factory()->for($user)->create();

        ContactMail::factory()->sent()->for($c1)->forSequenceStep($step1)->create();
        ContactMail::factory()->sent()->for($c2)->forSequenceStep($step1)->create();
        ContactMail::factory()->sent()->for($c2)->forSequenceStep($step2)->create();

        $aggregate = $this->service->stepStats($sequence->steps()->get());
        $single1 = $this->service->statsForStep($step1);
        $single2 = $this->service->statsForStep($step2);

        $this->assertSame($aggregate[$step1->id]['contacts'], $single1['contacts']);
        $this->assertSame($aggregate[$step2->id]['contacts'], $single2['contacts']);
    }

    public function test_mail_metrics_for_sequence_aggregates_across_all_steps(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step1 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'delay_type' => 'days']);
        $step2 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1, 'delay_type' => 'days']);

        $c1 = Contact::factory()->for($user)->create();
        $c2 = Contact::factory()->for($user)->create();
        $c3 = Contact::factory()->for($user)->create();
        $c4 = Contact::factory()->for($user)->create();

        ContactMail::factory()->delivered()->for($c1)->forSequenceStep($step1)->create();
        ContactMail::factory()->opened()->for($c2)->forSequenceStep($step1)->create();
        ContactMail::factory()->bounced()->for($c3)->forSequenceStep($step1)->create();
        ContactMail::factory()->unsubscribed()->for($c4)->forSequenceStep($step2)->create();
        ContactMail::factory()->pending()->for($c1)->forSequenceStep($step2)->create();

        $metrics = $this->service->mailMetricsForSequence($sequence->fresh()->load('steps'));

        $this->assertSame(5, $metrics['total']);
        $this->assertSame(4, $metrics['sent']);
        $this->assertSame(3, $metrics['delivered']);
        $this->assertSame(1, $metrics['pending']);
        $this->assertSame(1, $metrics['opened']);
        $this->assertSame(1, $metrics['bounced']);
        $this->assertSame(1, $metrics['unsubscribed']);
        $this->assertSame(0, $metrics['complained']);
        $this->assertSame(75.0, $metrics['delivery_rate']);
        $this->assertSame(25.0, $metrics['open_rate']);
        $this->assertSame(25.0, $metrics['unsubscribe_rate']);
    }

    public function test_mail_metrics_count_skipped_mails_apart_from_pending(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        ContactMail::factory()->skipped()->for(Contact::factory()->for($user))->forSequenceStep($step)->create();
        ContactMail::factory()->pending()->for(Contact::factory()->for($user))->forSequenceStep($step)->create();

        $stepMetrics = $this->service->mailMetricsForStep($step);
        $stepsMetrics = $this->service->mailMetricsForSteps(collect([$step]));

        $this->assertSame(1, $stepMetrics['skipped']);
        $this->assertSame(1, $stepMetrics['pending']);
        $this->assertSame(0, $stepMetrics['sent']);
        $this->assertSame(1, $stepsMetrics[$step->id]['skipped']);
    }

    public function test_mail_metrics_for_sequence_returns_zero_metrics_when_no_steps(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();

        $metrics = $this->service->mailMetricsForSequence($sequence->load('steps'));

        $this->assertSame(0, $metrics['total']);
        $this->assertSame(0, $metrics['sent']);
        $this->assertNull($metrics['delivery_rate']);
        $this->assertNull($metrics['open_rate']);
    }

    public function test_mail_metrics_for_step_scopes_to_single_step(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step1 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'delay_type' => 'days']);
        $step2 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1, 'delay_type' => 'days']);

        $c1 = Contact::factory()->for($user)->create();
        $c2 = Contact::factory()->for($user)->create();

        ContactMail::factory()->delivered()->for($c1)->forSequenceStep($step1)->create();
        ContactMail::factory()->opened()->for($c1)->forSequenceStep($step2)->create();
        ContactMail::factory()->bounced()->for($c2)->forSequenceStep($step2)->create();

        $step1Metrics = $this->service->mailMetricsForStep($step1);
        $step2Metrics = $this->service->mailMetricsForStep($step2);

        $this->assertSame(1, $step1Metrics['total']);
        $this->assertSame(1, $step1Metrics['delivered']);
        $this->assertSame(0, $step1Metrics['opened']);

        $this->assertSame(2, $step2Metrics['total']);
        $this->assertSame(1, $step2Metrics['opened']);
        $this->assertSame(1, $step2Metrics['bounced']);
    }

    public function test_mail_metrics_for_steps_returns_map_with_zero_for_empty_steps(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step1 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'delay_type' => 'days']);
        $step2 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1, 'delay_type' => 'days']);

        $c1 = Contact::factory()->for($user)->create();
        ContactMail::factory()->delivered()->for($c1)->forSequenceStep($step1)->create();

        $map = $this->service->mailMetricsForSteps($sequence->steps()->get());

        $this->assertArrayHasKey($step1->id, $map);
        $this->assertArrayHasKey($step2->id, $map);
        $this->assertSame(1, $map[$step1->id]['delivered']);
        $this->assertSame(0, $map[$step2->id]['total']);
        $this->assertSame(0, $map[$step2->id]['sent']);
        $this->assertNull($map[$step2->id]['open_rate']);
    }

    public function test_step_stats_initialises_steps_with_no_contacts_at_zero(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step1 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'delay_type' => 'days']);
        $step2 = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1, 'delay_type' => 'days']);

        $contact = Contact::factory()->for($user)->create();
        ContactMail::factory()->sent()->for($contact)->forSequenceStep($step1)->create();

        $steps = $sequence->steps()->get();
        $result = $this->service->stepStats($steps);

        $this->assertSame(1, $result[$step1->id]['contacts']);
        $this->assertSame(0, $result[$step2->id]['contacts']);
        $this->assertSame(0, $result[$step2->id]['unsubscribed']);
        $this->assertSame(0, $result[$step2->id]['clicked']);
    }
}
