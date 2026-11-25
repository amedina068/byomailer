<?php

namespace Tests\Feature;

use App\Http\Resources\NewsletterResource;
use App\Http\Resources\SequenceResource;
use App\Models\Contact;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\User;
use App\Services\EmailSendingGate;
use App\Services\NewsletterTransport;
use App\Services\SendDecision;
use App\Services\SendNewsletterService;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class SegmentResolutionTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, array<string, mixed>>
     */
    private function listFilter(array $values): array
    {
        return [['connector' => 'and', 'category' => 'lists', 'mode' => 'any', 'values' => $values]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tagFilter(string $tag): array
    {
        return [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => [$tag]]];
    }

    // ---- T-006: segment-only sequence enrollment honours the segment ----

    public function test_segment_only_sequence_does_not_enroll_non_matching_contact(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $segment = Segment::factory()->withFilters($this->listFilter([$list->id]))->create(['client_id' => $user->client_id]);

        $sequence = Sequence::factory()->for($user)->create(['status' => 'live', 'filters' => [], 'segment_id' => $segment->id]);
        SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'status' => 'live']);

        $contact = Contact::factory()->for($user)->create(); // not in the list

        $contact->syncSequences([$sequence->id], true);

        $this->assertFalse($contact->fresh()->sequences()->where('sequences.id', $sequence->id)->exists());
    }

    public function test_segment_only_sequence_enrolls_matching_contact(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $segment = Segment::factory()->withFilters($this->listFilter([$list->id]))->create(['client_id' => $user->client_id]);

        $sequence = Sequence::factory()->for($user)->create(['status' => 'live', 'filters' => [], 'segment_id' => $segment->id]);
        $step = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'status' => 'live']);

        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $contact->syncSequences([$sequence->id], true);

        $this->assertTrue($contact->fresh()->sequences()->where('sequences.id', $sequence->id)->exists());
        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'status' => 'pending',
        ]);
    }

    // ---- T-007: enforcement via the 4-site dispatch/prefilter widening ----

    public function test_segment_only_sequence_cancels_mails_when_list_change_breaks_membership(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $segment = Segment::factory()->withFilters($this->listFilter([$list->id]))->create(['client_id' => $user->client_id]);

        $sequence = Sequence::factory()->for($user)->create(['status' => 'live', 'filters' => [], 'segment_id' => $segment->id]);
        $step = SequenceStep::factory()->for($sequence)->create(['delay_days' => 3, 'status' => 'live']);

        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);
        $contact->syncSequences([$sequence->id], true);

        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id, 'mailable_id' => $step->id, 'status' => 'pending',
        ]);

        $contact->syncLists([]); // no longer matches the segment's list filter

        $this->assertDatabaseMissing('contact_mails', [
            'contact_id' => $contact->id, 'mailable_id' => $step->id, 'status' => 'pending',
        ]);
    }

    public function test_segment_only_sequence_cancels_mails_when_tag_change_breaks_membership(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create(['name' => 'vip']);
        $segment = Segment::factory()->withFilters($this->tagFilter('vip'))->create(['client_id' => $user->client_id]);

        $sequence = Sequence::factory()->for($user)->create(['status' => 'live', 'filters' => [], 'segment_id' => $segment->id]);
        $step = SequenceStep::factory()->for($sequence)->create(['delay_days' => 3, 'status' => 'live']);

        $contact = Contact::factory()->for($user)->create();
        $contact->syncTags([$tag->id]);
        $contact->syncSequences([$sequence->id], true);

        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id, 'mailable_id' => $step->id, 'status' => 'pending',
        ]);

        $contact->syncTags([]); // no longer matches the segment's tag filter

        $this->assertDatabaseMissing('contact_mails', [
            'contact_id' => $contact->id, 'mailable_id' => $step->id, 'status' => 'pending',
        ]);
    }

    public function test_empty_inline_no_segment_sequence_is_not_cancelled_on_list_change(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        $sequence = Sequence::factory()->for($user)->create(['status' => 'live', 'filters' => [], 'segment_id' => null]);
        $step = SequenceStep::factory()->for($sequence)->create(['delay_days' => 3, 'status' => 'live']);

        $contact = Contact::factory()->for($user)->create();
        $contact->syncSequences([$sequence->id], true);

        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id, 'mailable_id' => $step->id, 'status' => 'pending',
        ]);

        $contact->syncLists([]); // widening must NOT fire enforcement for a rule-less sequence

        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id, 'mailable_id' => $step->id, 'status' => 'pending',
        ]);
    }

    // ---- T-005: schedule gate receives the segment-resolved recipient count ----

    public function test_schedule_gate_uses_segment_filtered_recipient_count(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        // One matching subscribed contact, two non-matching — gate must see 1, not 3.
        Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'pro']]);
        Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'free']]);
        Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'free']]);

        $segment = Segment::factory()->withFilters([
            ['connector' => 'and', 'category' => 'attributes', 'mode' => 'all', 'values' => [
                ['key' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
            ]],
        ])->create(['client_id' => $user->client_id]);

        $newsletter = Newsletter::factory()->for($user)->create(['filters' => [], 'segment_id' => $segment->id]);

        $captured = null;
        $mock = Mockery::mock(EmailSendingGate::class);
        $mock->shouldReceive('evaluate')
            ->once()
            ->andReturnUsing(function (User $u, int $count) use (&$captured): SendDecision {
                $captured = $count;

                return SendDecision::block('blocked for test', 'test');
            });
        $this->app->instance(EmailSendingGate::class, $mock);

        $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
        ]);

        $this->assertSame(1, $captured);
    }

    private function fakeBatch(): Batch
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('add')->andReturnSelf();

        return $batch;
    }

    public function test_send_newsletter_service_targets_only_segment_matches(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'pro']]);
        Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'free']]);
        Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'free']]);

        $segment = Segment::factory()->withFilters([
            ['connector' => 'and', 'category' => 'attributes', 'mode' => 'all', 'values' => [
                ['key' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
            ]],
        ])->create(['client_id' => $user->client_id]);

        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => 'queued',
            'filters' => [],
            'segment_id' => $segment->id,
        ]);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldReceive('createTemplate')->andReturn('tpl-x');
        $transport->shouldReceive('getChunkSize')->andReturn(50);
        $this->app->instance(NewsletterTransport::class, $transport);

        app(SendNewsletterService::class)->send($newsletter, $this->fakeBatch());

        // Inline filters are empty; only the segment's attribute filter (1 of 3) must drive the send.
        $this->assertSame(1, $newsletter->fresh()->total_contacts_reached);
    }

    public function test_send_newsletter_service_with_zero_segment_matches_reaches_no_one(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        // Three subscribed contacts, none matching the segment.
        Contact::factory()->count(3)->for($user)->create(['extra_attributes' => ['plan' => 'free']]);

        $segment = Segment::factory()->withFilters([
            ['connector' => 'and', 'category' => 'attributes', 'mode' => 'all', 'values' => [
                ['key' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
            ]],
        ])->create(['client_id' => $user->client_id]);

        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => 'queued',
            'filters' => [],
            'segment_id' => $segment->id,
        ]);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldReceive('createTemplate')->andReturn('tpl-x');
        $transport->shouldReceive('getChunkSize')->andReturn(50);
        $this->app->instance(NewsletterTransport::class, $transport);

        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('add')->never();

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $this->assertSame(0, $newsletter->fresh()->total_contacts_reached);
    }

    // ---- T-008: resource exposes segment_id + segment-resolved filters ----

    public function test_newsletter_resource_exposes_segment_filters(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $filters = $this->listFilter([MailList::factory()->for($user)->create()->id]);
        $segment = Segment::factory()->withFilters($filters)->create(['client_id' => $user->client_id]);
        $newsletter = Newsletter::factory()->for($user)->create(['filters' => [], 'segment_id' => $segment->id]);

        $payload = NewsletterResource::make($newsletter->load('segment'))->resolve(Request::create('/'));

        $this->assertSame($segment->id, $payload['segment_id']);
        $this->assertSame($filters, $payload['filters']);
    }

    public function test_sequence_resource_exposes_segment_filters(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $filters = $this->listFilter([MailList::factory()->for($user)->create()->id]);
        $segment = Segment::factory()->withFilters($filters)->create(['client_id' => $user->client_id]);
        $sequence = Sequence::factory()->for($user)->create(['filters' => [], 'segment_id' => $segment->id]);

        $payload = SequenceResource::make($sequence->load('segment'))->resolve(Request::create('/'));

        $this->assertSame($segment->id, $payload['segment_id']);
        $this->assertSame($filters, $payload['filters']);
    }
}
