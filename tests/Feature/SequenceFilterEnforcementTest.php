<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\User;
use App\Services\SequenceStepScheduler;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class SequenceFilterEnforcementTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_contact_matching_filters_is_scheduled_for_sequence(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $contact->syncSequences([$sequence->id], true);

        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'status' => 'pending',
        ]);
    }

    public function test_contact_not_matching_filters_is_skipped_on_initial_schedule(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);

        $contact = Contact::factory()->for($user)->create();

        $contact->syncSequences([$sequence->id], true);

        $this->assertDatabaseMissing('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
        ]);
    }

    public function test_contact_not_matching_filters_is_not_attached_to_sequence(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        $contact = Contact::factory()->for($user)->create();

        $contact->syncSequences([$sequence->id], true);

        $this->assertFalse($contact->sequences()->where('sequences.id', $sequence->id)->exists());
    }

    public function test_contact_matching_filters_is_attached_to_sequence(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $contact->syncSequences([$sequence->id], true);

        $this->assertTrue($contact->sequences()->where('sequences.id', $sequence->id)->exists());
    }

    public function test_sequence_with_empty_filters_schedules_all_contacts(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [],
        ]);

        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->syncSequences([$sequence->id], true);

        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
        ]);
    }

    public function test_contact_removed_from_sequence_when_list_change_breaks_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 3,
            'status' => 'live',
        ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);
        $contact->syncSequences([$sequence->id], true);

        $this->assertTrue($contact->sequences()->where('sequences.id', $sequence->id)->exists());
        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'status' => 'pending',
        ]);

        $contact->syncLists([]);

        $this->assertTrue($contact->fresh()->sequences()->where('sequences.id', $sequence->id)->exists());
        $this->assertDatabaseMissing('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_id' => $step->id,
            'status' => 'skipped',
        ]);
        $this->assertNotNull($sequence->contacts()->whereKey($contact->id)->first()->pivot->completed_at);
    }

    public function test_contact_stays_in_sequence_when_list_change_still_matches(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $listA = MailList::factory()->for($user)->create();
        $listB = MailList::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$listA->id, $listB->id],
                ],
            ],
        ]);

        SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach([$listA->id, $listB->id]);
        $contact->syncSequences([$sequence->id], true);

        $contact->syncLists([$listB->id]);

        $this->assertTrue($contact->fresh()->sequences()->where('sequences.id', $sequence->id)->exists());
    }

    public function test_contact_removed_from_sequence_when_tag_change_breaks_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create(['name' => 'free-user']);

        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'tags',
                    'mode' => 'any',
                    'values' => ['free-user'],
                ],
            ],
        ]);

        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 3,
            'status' => 'live',
        ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->tags()->attach($tag);
        $contact->syncSequences([$sequence->id], true);

        $this->assertTrue($contact->sequences()->where('sequences.id', $sequence->id)->exists());

        $contact->syncTags([]);

        $this->assertTrue($contact->fresh()->sequences()->where('sequences.id', $sequence->id)->exists());
        $this->assertDatabaseMissing('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'status' => 'pending',
        ]);
    }

    public function test_next_step_not_scheduled_when_contact_no_longer_matches(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00'));

        $user = User::factory()->create();

        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        $step1 = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);
        $step2 = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 3,
            'status' => 'live',
        ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);
        $contact->syncSequences([$sequence->id], true);

        $contactMail = ContactMail::query()
            ->where('contact_id', $contact->id)
            ->where('mailable_id', $step1->id)
            ->first();

        $contactMail->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

        $contact->lists()->detach($list);

        app(SequenceStepScheduler::class)->scheduleNextStep($contactMail);

        $this->assertTrue($contact->fresh()->sequences()->where('sequences.id', $sequence->id)->exists());
        $this->assertDatabaseMissing('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step2->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_mail_not_sent_when_contact_no_longer_matches_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00'));

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);
        $step->message()->withoutGlobalScopes()->update([
            'from_email' => 'test@example.com',
            'from_name' => 'Test',
        ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);
        Tenant::run($user->client_id, fn () => $contact->syncSequences([$sequence->id], true));

        $contact->lists()->detach($list);

        $mailManager = Mockery::mock(MailManager::class);
        $mailManager->shouldNotReceive('build');
        $this->app->instance(MailManager::class, $mailManager);

        $this->withoutSesSendingLimit();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $contactMail = ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $step->id)
            ->first();

        $this->assertSame('skipped', $contactMail->status);
        $this->assertNull($contactMail->sent_at);

        Carbon::setTestNow();
    }

    public function test_user_can_save_sequence_filters(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);

        $response = $this->actingAs($user)->patch(route('sequences.filters.update', $sequence->id), [
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Filters saved.');

        $sequence->refresh();
        $this->assertCount(1, $sequence->filters);
        $this->assertSame('lists', $sequence->filters[0]['category']);
        $this->assertSame([$list->id], $sequence->filters[0]['values']);
    }

    public function test_user_can_clear_sequence_filters(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'draft',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->patch(route('sequences.filters.update', $sequence->id), [
            'filters' => [],
        ]);

        $response->assertRedirect();

        $sequence->refresh();
        $this->assertEmpty($sequence->filters);
    }

    public function test_sequence_resource_includes_filters(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'draft',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        SequenceStep::factory()->for($sequence)->create();

        $response = $this->actingAs($user)->get(route('sequences.show', $sequence->id));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sequences/Show')
            ->has('sequence.filters', 1)
            ->where('sequence.filters.0.category', 'lists')
        );
    }

    public function test_mail_not_sent_when_contact_no_longer_matches_tag_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00'));

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'premium']);

        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'tags',
                    'mode' => 'any',
                    'values' => ['premium'],
                ],
            ],
        ]);

        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->tags()->attach($tag);
        Tenant::run($user->client_id, fn () => $contact->syncSequences([$sequence->id], true));

        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'status' => 'pending',
        ]);

        $contact->tags()->detach($tag);

        $mailManager = Mockery::mock(MailManager::class);
        $mailManager->shouldNotReceive('build');
        $this->app->instance(MailManager::class, $mailManager);

        $this->withoutSesSendingLimit();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $contactMail = ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $step->id)
            ->first();

        $this->assertSame('skipped', $contactMail->status);
        $this->assertNull($contactMail->sent_at);

        Carbon::setTestNow();
    }

    public function test_contact_matching_filters_is_scheduled_for_hours_based_step(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 6,
            'delay_type' => 'hours',
            'status' => 'live',
        ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $contact->syncSequences([$sequence->id], true);

        $this->assertDatabaseHas('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'status' => 'pending',
        ]);
    }

    public function test_pending_mails_cancelled_when_contact_removed_from_sequence(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        $step1 = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);
        $step2 = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 5,
            'status' => 'live',
        ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);
        $contact->syncSequences([$sequence->id], true);

        $this->assertSame(1, ContactMail::where('contact_id', $contact->id)->where('status', 'pending')->count());

        $contact->syncLists([]);

        $this->assertSame(0, ContactMail::where('contact_id', $contact->id)->where('status', 'pending')->count());
    }
}
