<?php

namespace Tests\Feature;

use App\Enums\SequenceStatus;
use App\Jobs\RunAutomationForContactJob;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class SequenceManagementTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_the_sequence_pages_and_the_dashboard_send_the_status_labels(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => SequenceStatus::Live]);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => SequenceStatus::Draft]);
        $this->actingAs($user);

        $hasStatuses = fn (Assert $page): Assert => $page
            ->where('sequenceStatuses', [['value' => 'draft', 'label' => 'Draft'], ['value' => 'live', 'label' => 'Live']]);

        $this->get(route('sequences.index'))->assertInertia(fn (Assert $page) => $hasStatuses($page->component('sequences/Index')));
        $this->get(route('sequences.show', $sequence))->assertInertia(fn (Assert $page) => $hasStatuses($page->component('sequences/Show')));
        $this->get(route('sequences.steps.show', ['sequence' => $sequence->id, 'sequenceStep' => $step->id]))
            ->assertInertia(fn (Assert $page) => $hasStatuses($page->component('sequences/steps/Show')));
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $hasStatuses($page->component('Dashboard')));
    }

    public function test_user_can_view_sequences_index(): void
    {
        $user = User::factory()->create();
        $sequences = Sequence::factory()->count(2)->for($user)->create();
        Sequence::factory()->create();

        $response = $this->actingAs($user)->get(route('sequences.index'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('sequences/Index')
            ->where('sequences', fn ($collection): bool => collect($collection)->count() === 2
                && collect($collection)->pluck('id')->diff($sequences->pluck('id'))->isEmpty())
        );
    }

    public function test_sequences_index_exposes_active_and_completed_counts(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();

        $completed = Contact::factory()->count(2)->for($user)->create();
        foreach ($completed as $contact) {
            $sequence->contacts()->attach($contact->id, ['completed_at' => now()]);
        }

        $active = Contact::factory()->count(3)->for($user)->create();
        foreach ($active as $contact) {
            $sequence->contacts()->attach($contact->id);
        }

        $this->actingAs($user)->get(route('sequences.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sequences/Index')
                ->where('sequences.0.contacts_count', 5)
                ->where('sequences.0.completed_count', 2)
                ->where('sequences.0.active_count', 3));
    }

    public function test_sequences_index_sorts_live_before_draft_then_by_name(): void
    {
        $user = User::factory()->create();

        $draftA = Sequence::factory()->for($user)->create(['name' => 'Alpha', 'status' => 'draft']);
        $liveC = Sequence::factory()->for($user)->create(['name' => 'Charlie', 'status' => 'live']);
        $draftB = Sequence::factory()->for($user)->create(['name' => 'Bravo', 'status' => 'draft']);
        $liveA = Sequence::factory()->for($user)->create(['name' => 'Alpha Live', 'status' => 'live']);

        $response = $this->actingAs($user)->get(route('sequences.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('sequences/Index')
            ->where('sequences', fn ($collection): bool => collect($collection)->pluck('id')->values()->all() === [
                $liveA->id,
                $liveC->id,
                $draftA->id,
                $draftB->id,
            ])
        );
    }

    public function test_user_can_update_sequence_name(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create([
            'name' => 'Onboarding Journey',
        ]);

        $response = $this->actingAs($user)->patch(route('sequences.update', $sequence), [
            'name' => 'Revamped Journey',
        ]);

        $response->assertRedirect();

        $sequence->refresh();

        $this->assertSame('Revamped Journey', $sequence->name);
    }

    public function test_user_cannot_update_sequence_they_do_not_own(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->create([
            'name' => 'Growth Series',
        ]);

        $response = $this->actingAs($user)->patch(route('sequences.update', $sequence), [
            'name' => 'Updated Series',
        ]);

        $response->assertNotFound();

        $sequence->refresh();

        $this->assertSame('Growth Series', $sequence->name);
    }

    public function test_contact_can_belong_to_multiple_sequences(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $contact = Contact::factory()->for($user)->create();
        $sequences = Sequence::factory()->count(2)->for($user)->create(['status' => 'live']);

        $contact->syncSequences($sequences->pluck('id')->all());

        $contact->load('sequences');

        $this->assertCount(2, $contact->sequences);
        $this->assertTrue($contact->sequences->pluck('id')->diff($sequences->pluck('id'))->isEmpty());
        $this->assertTrue($sequences->first()->contacts()->whereKey($contact->id)->exists());
    }

    public function test_added_to_sequence_automations_dispatch_jobs(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAsTenant($user->client_id);
        $contact = Contact::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);

        $automation = Automation::factory()->for($user)->create([
            'condition' => [
                'type' => 'added_to_sequence',
                'value' => $sequence->id,
            ],
            'actions' => [],
        ]);

        $contact->syncSequences([$sequence->id], true);

        Queue::assertPushed(RunAutomationForContactJob::class, function (RunAutomationForContactJob $job) use ($automation, $contact): bool {
            return $job->automation->is($automation)
                && $job->contact->is($contact);
        });
    }

    public function test_attaching_contact_to_live_sequence_schedules_first_step(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00'));

        $user = User::factory()->create();

        $this->actingAsTenant($user->client_id);
        $contact = Contact::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 2,
            'status' => 'live',
        ]);
        $step->message()->update([
            'subject' => 'Welcome to our product',
            'from_email' => 'hello@example.com',
            'from_name' => 'Growth Team',
        ]);

        $contact->syncSequences([$sequence->id], true);

        $mail = ContactMail::query()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $step->id)
            ->first();

        $this->assertNotNull($mail);
        $this->assertSame('pending', $mail->status);
        $this->assertTrue($mail->scheduled_at->equalTo(Carbon::now()->addDays(2)));

        Carbon::setTestNow();
    }

    public function test_due_contact_mails_are_sent_by_command(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00'));

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);
        $mailManager->shouldReceive('build')
            ->once()
            ->withArgs(fn (array $config): bool => ($config['transport'] ?? null) === 'ses'
                && ($config['name'] ?? null) === 'ses-user-'.$user->id)
            ->andReturn($mailer);
        $mailer->shouldReceive('send')->once()->andReturnNull();
        $this->app->instance(MailManager::class, $mailManager);

        $contact = Contact::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);
        $step->message()->withoutGlobalScopes()->update([
            'subject' => 'Sequence welcome',
            'from_email' => 'hello@example.com',
            'from_name' => 'Lifecycle',
        ]);

        Tenant::run($user->client_id, fn () => $contact->syncSequences([$sequence->id], true));

        $contactMail = ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $step->id)
            ->first();

        $this->assertNotNull($contactMail);
        $this->assertSame('pending', $contactMail->status);

        $this->withoutSesSendingLimit();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        Carbon::setTestNow();
    }

    public function test_contact_can_unsubscribe_from_sequence_mail_via_signed_url(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);
        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'name' => 'Drip Welcome',
        ]);

        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);

        Tenant::run($user->client_id, fn () => $contact->syncSequences([$sequence->id], true));

        $contactMail = $contact->mails()->withoutGlobalScopes()->first();

        $url = URL::temporarySignedRoute('contact-mails.unsubscribe', now()->addMinutes(5), [
            'contactMail' => $contactMail->uuid,
        ]);

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Unsubscribe')
            ->where('contact.email', $contact->email)
        );

        $contact->refresh();
        $this->assertFalse((bool) $contact->unsubscribed_by_contact);

        $response = $this->post('/unsubscribe/'.$contactMail->uuid);

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Unsubscribed')
            ->where('contact.email', $contact->email)
        );

        $contact->refresh();
        $contactMail->refresh();

        $this->assertTrue($contact->unsubscribed_by_contact);
        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertNotNull($contactMail->unsubscribed_at);
    }

    public function test_user_can_view_sequence_creation_form(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('sequences.create'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('sequences/Create'));
    }

    public function test_user_can_store_sequence(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('sequences.store'), [
            'name' => 'Customer Onboarding',
        ]);

        $sequence = Sequence::query()->where('name', 'Customer Onboarding')->first();

        $response->assertRedirect(route('sequences.show', $sequence?->id));

        $this->assertNotNull($sequence);
        $this->assertSame(SequenceStatus::Draft, $sequence->status);
        $this->assertSame($user->id, $sequence->user_id);
    }

    public function test_user_can_view_sequence_with_steps(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create([
            'name' => 'Engagement Series',
        ]);
        $steps = SequenceStep::factory()
            ->count(3)
            ->for($sequence)
            ->sequence(
                ['delay_days' => 0],
                ['delay_days' => 3],
                ['delay_days' => 7],
            )
            ->create();

        $response = $this->actingAs($user)->get(route('sequences.show', $sequence->id));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('sequences/Show')
            ->where('sequence.name', $sequence->name)
            ->has('sequence.steps', 3)
            ->where('sequence.steps.0.id', $steps->get(0)->id)
        );

        $response = $this->actingAs($user)->get(route('sequences.steps.show', [
            'sequence' => $sequence->id,
            'sequenceStep' => $steps->get(1)->id,
        ]));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('sequences/steps/Show')
            ->where('step.id', $steps->get(1)->id)
            ->where('stepIndex', 1)
        );
    }

    public function test_user_can_create_sequence_step(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $payload = [
            'delay_days' => 2,
            'delay_type' => 'days',
            'email_identity_id' => $identity->id,
        ];

        $response = $this->actingAs($user)->post(route('sequences.steps.store', $sequence->id), $payload);

        $step = SequenceStep::query()->where('sequence_id', $sequence->id)->first();

        $response->assertRedirect(route('sequences.steps.show', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step?->id,
        ]));

        $this->assertNotNull($step);
        $this->assertSame(2, $step->delay_days);
        $this->assertSame(SequenceStatus::Draft, $step->status);

        $this->assertDatabaseHas('messages', [
            'messageable_type' => SequenceStep::class,
            'messageable_id' => $step->id,
            'email_identity_id' => $identity->id,
        ]);
    }

    public function test_user_can_update_sequence_step(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 1,
            'status' => 'draft',
        ]);
        $identity = $user->emailIdentities()->firstOrFail();

        $payload = [
            'delay_days' => 5,
            'delay_type' => 'days',
            'subject' => 'Updated subject',
            'preview_text' => 'Quick teaser',
            'content' => '<p>Updated content here</p>',
            'email_identity_id' => $identity->id,
        ];

        $response = $this->actingAs($user)->patch(
            route('sequences.steps.update', [$sequence->id, $step->id]),
            $payload,
        );

        $response->assertRedirect(route('sequences.steps.show', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step->id,
        ]));
        $response->assertSessionHas('success', 'Step saved.');

        $step->refresh();
        $this->assertSame(5, $step->delay_days);

        $this->assertDatabaseHas('messages', [
            'messageable_type' => SequenceStep::class,
            'messageable_id' => $step->id,
            'subject' => 'Updated subject',
            'preview_text' => 'Quick teaser',
            'html_content' => '<p>Updated content here</p>',
            'text_content' => 'Updated content here',
        ]);
    }

    public function test_sequence_resource_includes_delay_type_for_steps(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 6,
            'delay_type' => 'hours',
        ]);

        $response = $this->actingAs($user)->get(route('sequences.show', $sequence->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('sequences/Show')
            ->has('sequence.steps', 1)
            ->where('sequence.steps.0.delay_type', 'hours')
            ->where('sequence.steps.0.delay_days', 6)
        );
    }

    public function test_sequence_pages_count_the_contacts_and_due_emails_for_the_status_dialog(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);
        $contacts = Contact::factory()->for($user)->count(3)->create();
        $sequence->contacts()->attach($contacts->pluck('id'));
        ContactMail::factory()->forSequenceStep($step)->pending()->create(['contact_id' => $contacts[0]->id, 'scheduled_at' => now()->subHour()]);
        ContactMail::factory()->forSequenceStep($step)->pending()->create(['contact_id' => $contacts[1]->id, 'scheduled_at' => now()->subMinute()]);
        ContactMail::factory()->forSequenceStep($step)->pending()->create(['contact_id' => $contacts[2]->id, 'scheduled_at' => now()->addDay()]);

        $this->actingAs($user)->get(route('sequences.show', $sequence->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('sequences/Show')
                ->where('statusImpact.enrolled_contacts', 3)
                ->where('statusImpact.pending_mails_due_now', 2)
            );

        $this->actingAs($user)->get(route('sequences.steps.show', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step->id,
        ]))->assertInertia(fn (Assert $page) => $page
            ->component('sequences/steps/Show')
            ->where('statusImpact.enrolled_contacts', 3)
            ->where('statusImpact.pending_mails_due_now', 2)
        );
    }

    public function test_user_can_toggle_sequence_status(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'draft',
        ]);

        $response = $this->actingAs($user)->patch(route('sequences.status.update', $sequence->id), [
            'status' => 'live',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Sequence activated.');
        $this->assertSame(SequenceStatus::Live, $sequence->fresh()->status);
    }

    public function test_user_can_toggle_sequence_step_status(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create([
            'status' => 'draft',
        ]);

        $response = $this->actingAs($user)->patch(route('sequences.steps.status.update', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step->id,
        ]), [
            'status' => 'live',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Step activated.');
        $this->assertSame(SequenceStatus::Live, $step->fresh()->status);
    }
}
