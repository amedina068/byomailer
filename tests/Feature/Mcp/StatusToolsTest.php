<?php

namespace Tests\Feature\Mcp;

use App\Enums\NewsletterStatus;
use App\Enums\SequenceStatus;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\ScheduleNewsletterTool;
use App\Mcp\Tools\SetAutomationStatusTool;
use App\Mcp\Tools\SetSequenceStatusTool;
use App\Mcp\Tools\SetSequenceStepStatusTool;
use App\Mcp\Tools\UpdateAccountProfileTool;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Rules\SequenceMustBeLive;
use App\Services\EmailSendingGate;
use App\Services\SendableContentCheck;
use App\Services\SendDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Attributes\Instructions;
use ReflectionClass;
use Tests\TestCase;

class StatusToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_tools_use_the_documented_names(): void
    {
        $this->assertSame('set-sequence-status', app(SetSequenceStatusTool::class)->name());
        $this->assertSame('set-sequence-step-status', app(SetSequenceStepStatusTool::class)->name());
        $this->assertSame('set-automation-status', app(SetAutomationStatusTool::class)->name());
        $this->assertSame('schedule-newsletter', app(ScheduleNewsletterTool::class)->name());
    }

    public function test_set_sequence_status_previews_the_activation_without_saving(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);
        $contacts = Contact::factory()->for($user)->count(2)->create();
        $sequence->contacts()->attach($contacts->pluck('id'));
        ContactMail::factory()->forSequenceStep($step)->pending()->create(['contact_id' => $contacts[0]->id, 'scheduled_at' => now()->subHour()]);
        ContactMail::factory()->forSequenceStep($step)->pending()->create(['contact_id' => $contacts[1]->id, 'scheduled_at' => now()->addDay()]);

        ByoMailerServer::actingAs($user)->tool(SetSequenceStatusTool::class, [
            'sequence_id' => $sequence->id,
            'status' => 'live',
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', false)
            ->where('change', 'activate')
            ->where('status', 'draft')
            ->where('new_status', 'live')
            ->where('enrolled_contacts', 2)
            ->where('pending_mails', 2)
            ->where('pending_mails_due_now', 1)
            ->where('live_steps', 1)
            ->where('total_steps', 1)
            ->etc());

        $this->assertSame(SequenceStatus::Draft, $sequence->refresh()->status);
    }

    public function test_set_sequence_status_activates_after_confirmation(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(SetSequenceStatusTool::class, [
            'sequence_id' => $sequence->id,
            'status' => 'live',
            'confirm_live' => true,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', true)
            ->where('status', 'live')
            ->etc());

        $this->assertSame(SequenceStatus::Live, $sequence->refresh()->status);
    }

    public function test_set_sequence_status_pause_preview_warns_about_live_automations(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $automation = Automation::factory()->for($user)->create([
            'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
            'actions' => [['type' => 'add_sequence', 'value' => $sequence->id]],
        ]);
        Automation::factory()->for($user)->paused()->create([
            'actions' => [['type' => 'add_sequence', 'value' => $sequence->id]],
        ]);

        ByoMailerServer::actingAs($user)->tool(SetSequenceStatusTool::class, [
            'sequence_id' => $sequence->id,
            'status' => 'paused',
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', false)
            ->where('change', 'pause')
            ->where('live_automation_ids', [$automation->id])
            ->etc())
            ->assertSee('They stay pending, and when you activate the sequence again')
            ->assertDontSee('it is deleted');

        $this->assertSame(SequenceStatus::Live, $sequence->refresh()->status);
    }

    public function test_set_sequence_status_pause_preview_names_the_live_automations_of_a_teammate(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->for($owner->client)->create();
        $sequence = Sequence::factory()->for($owner)->create(['status' => 'live']);
        $automation = Automation::factory()->for($teammate)->create([
            'actions' => [['type' => 'add_sequence', 'value' => $sequence->id]],
        ]);

        ByoMailerServer::actingAs($owner)->tool(SetSequenceStatusTool::class, [
            'sequence_id' => $sequence->id,
            'status' => 'paused',
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('live_automation_ids', [$automation->id])
            ->etc());
    }

    public function test_set_sequence_status_pauses_after_confirmation_with_the_dashboard_value(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);

        ByoMailerServer::actingAs($user)->tool(SetSequenceStatusTool::class, [
            'sequence_id' => $sequence->id,
            'status' => 'paused',
            'confirm_live' => true,
        ])->assertOk()->assertSee('"applied":true');

        $this->assertSame(SequenceStatus::Draft, $sequence->refresh()->status);
    }

    public function test_set_sequence_status_returns_the_stored_status_value_after_a_pause(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);

        ByoMailerServer::actingAs($user)->tool(SetSequenceStatusTool::class, [
            'sequence_id' => $sequence->id,
            'status' => 'paused',
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('status', 'live')
            ->where('new_status', 'draft')
            ->etc());

        ByoMailerServer::actingAs($user)->tool(SetSequenceStatusTool::class, [
            'sequence_id' => $sequence->id,
            'status' => 'draft',
            'confirm_live' => true,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', true)
            ->where('status', 'draft')
            ->etc());
    }

    public function test_set_sequence_step_status_returns_the_stored_status_values(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);

        ByoMailerServer::actingAs($user)->tool(SetSequenceStepStatusTool::class, [
            'sequence_step_id' => $step->id,
            'status' => 'draft',
            'confirm_live' => true,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', true)
            ->where('sequence_status', 'draft')
            ->where('status', 'draft')
            ->etc());
    }

    public function test_set_sequence_status_does_nothing_when_the_status_already_matches(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);

        ByoMailerServer::actingAs($user)->tool(SetSequenceStatusTool::class, [
            'sequence_id' => $sequence->id,
            'status' => 'live',
            'confirm_live' => true,
        ])->assertOk()->assertSee(['"applied":false', 'is already live']);
    }

    public function test_set_sequence_status_refuses_when_the_account_cannot_send(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $this->mock(EmailSendingGate::class)
            ->shouldReceive('evaluate')
            ->andReturn(SendDecision::block('Your free sending allowance is used up.', 'free_limit_reached'));

        ByoMailerServer::actingAs($user)->tool(SetSequenceStatusTool::class, [
            'sequence_id' => $sequence->id,
            'status' => 'live',
            'confirm_live' => true,
        ])->assertHasErrors(["Sequence #{$sequence->id} cannot go live: Your free sending allowance is used up."]);

        $this->assertSame(SequenceStatus::Draft, $sequence->refresh()->status);
    }

    public function test_set_sequence_status_errors_for_another_clients_sequence(): void
    {
        $user = User::factory()->create();
        $foreign = Sequence::factory()->create(['status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(SetSequenceStatusTool::class, [
            'sequence_id' => $foreign->id,
            'status' => 'live',
            'confirm_live' => true,
        ])->assertHasErrors(["No sequence found with id {$foreign->id}."]);

        $this->assertSame(SequenceStatus::Draft, $foreign->refresh()->status);
    }

    public function test_set_sequence_step_status_previews_the_pause_with_pending_mails(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);
        $contact = Contact::factory()->for($user)->create();
        $sequence->contacts()->attach($contact->id);
        ContactMail::factory()->forSequenceStep($step)->pending()->create(['contact_id' => $contact->id]);

        ByoMailerServer::actingAs($user)->tool(SetSequenceStepStatusTool::class, [
            'sequence_step_id' => $step->id,
            'status' => 'paused',
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', false)
            ->where('change', 'pause')
            ->where('enrolled_contacts', 1)
            ->where('pending_mails', 1)
            ->etc());

        $this->assertSame(SequenceStatus::Live, $step->refresh()->status);
    }

    public function test_set_sequence_step_status_activates_after_confirmation(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(SetSequenceStepStatusTool::class, [
            'sequence_step_id' => $step->id,
            'status' => 'live',
        ])->assertOk()->assertSee('The sequence is not live, so this step sends nothing until the sequence goes live.');

        $this->assertSame(SequenceStatus::Draft, $step->refresh()->status);

        ByoMailerServer::actingAs($user)->tool(SetSequenceStepStatusTool::class, [
            'sequence_step_id' => $step->id,
            'status' => 'live',
            'confirm_live' => true,
        ])->assertOk()->assertSee('"applied":true');

        $this->assertSame(SequenceStatus::Live, $step->refresh()->status);
    }

    public function test_set_sequence_step_status_refuses_a_step_without_a_subject(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'draft']);
        $step->message->update(['subject' => '']);

        ByoMailerServer::actingAs($user)->tool(SetSequenceStepStatusTool::class, [
            'sequence_step_id' => $step->id,
            'status' => 'live',
            'confirm_live' => true,
        ])->assertHasErrors([SendableContentCheck::SUBJECT_MESSAGE]);

        $this->assertSame(SequenceStatus::Draft, $step->refresh()->status);
    }

    public function test_set_sequence_step_status_errors_for_another_clients_step(): void
    {
        $user = User::factory()->create();
        $foreign = SequenceStep::factory()->create(['status' => 'live']);

        ByoMailerServer::actingAs($user)->tool(SetSequenceStepStatusTool::class, [
            'sequence_step_id' => $foreign->id,
            'status' => 'paused',
            'confirm_live' => true,
        ])->assertHasErrors(["No sequence step found with id {$foreign->id}."]);

        $this->assertSame(SequenceStatus::Live, $foreign->refresh()->status);
    }

    public function test_set_automation_status_previews_then_activates(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live', 'name' => 'Limit flow']);
        $automation = Automation::factory()->for($user)->paused()->create([
            'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
            'actions' => [['type' => 'add_sequence', 'value' => $sequence->id]],
        ]);

        ByoMailerServer::actingAs($user)->tool(SetAutomationStatusTool::class, [
            'automation_id' => $automation->id,
            'is_live' => true,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', false)
            ->where('change', 'activate')
            ->where('target_sequences.0.name', 'Limit flow')
            ->where('target_sequences.0.status', 'live')
            ->etc());

        $this->assertFalse($automation->refresh()->is_live);

        ByoMailerServer::actingAs($user)->tool(SetAutomationStatusTool::class, [
            'automation_id' => $automation->id,
            'is_live' => true,
            'confirm_live' => true,
        ])->assertOk()->assertSee(['"applied":true', '"is_live":true']);

        $this->assertTrue($automation->refresh()->is_live);
    }

    public function test_set_automation_status_refuses_activation_while_its_sequence_is_a_draft(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $automation = Automation::factory()->for($user)->paused()->create([
            'actions' => [['type' => 'add_sequence', 'value' => $sequence->id]],
        ]);

        ByoMailerServer::actingAs($user)->tool(SetAutomationStatusTool::class, [
            'automation_id' => $automation->id,
            'is_live' => true,
            'confirm_live' => true,
        ])->assertHasErrors([SequenceMustBeLive::MESSAGE, 'set-sequence-status']);

        $this->assertFalse($automation->refresh()->is_live);
    }

    public function test_set_automation_status_pauses_after_confirmation(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->for($user)->create();

        ByoMailerServer::actingAs($user)->tool(SetAutomationStatusTool::class, [
            'automation_id' => $automation->id,
            'is_live' => false,
        ])->assertOk()->assertSee(['"applied":false', 'Preview only']);

        $this->assertTrue($automation->refresh()->is_live);

        ByoMailerServer::actingAs($user)->tool(SetAutomationStatusTool::class, [
            'automation_id' => $automation->id,
            'is_live' => false,
            'confirm_live' => true,
        ])->assertOk()->assertSee('"applied":true');

        $this->assertFalse($automation->refresh()->is_live);
    }

    public function test_set_automation_status_errors_for_another_clients_automation(): void
    {
        $user = User::factory()->create();
        $foreign = Automation::factory()->create();

        ByoMailerServer::actingAs($user)->tool(SetAutomationStatusTool::class, [
            'automation_id' => $foreign->id,
            'is_live' => false,
            'confirm_live' => true,
        ])->assertHasErrors(["No automation found with id {$foreign->id}."]);

        $this->assertTrue($foreign->refresh()->is_live);
    }

    public function test_schedule_newsletter_previews_then_schedules(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->count(3)->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);
        $sendAt = now()->addDays(2)->startOfMinute();

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'schedule',
            'scheduled_at' => $sendAt->toIso8601String(),
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', false)
            ->where('change', 'schedule')
            ->where('recipient_count', 3)
            ->where('new_scheduled_at', $sendAt->copy()->utc()->toIso8601String())
            ->etc());

        $this->assertSame(NewsletterStatus::Draft, $newsletter->refresh()->status);

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'schedule',
            'scheduled_at' => $sendAt->toIso8601String(),
            'confirm_live' => true,
        ])->assertOk()->assertSee(['"applied":true', '"status":"scheduled"']);

        $newsletter->refresh();
        $this->assertSame(NewsletterStatus::Scheduled, $newsletter->status);
        $this->assertTrue($newsletter->scheduled_at->equalTo($sendAt));
    }

    public function test_schedule_newsletter_refuses_a_time_in_the_past(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'schedule',
            'scheduled_at' => now()->subMinute()->toIso8601String(),
            'confirm_live' => true,
        ])->assertHasErrors(['The scheduled_at time must be in the future.']);

        $this->assertSame(NewsletterStatus::Draft, $newsletter->refresh()->status);
    }

    public function test_schedule_newsletter_refuses_a_newsletter_without_a_sender(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);
        $newsletter->message->update(['email_identity_id' => null]);

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'schedule',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'confirm_live' => true,
        ])->assertHasErrors(["Newsletter #{$newsletter->id} has no sender."]);

        $this->assertSame(NewsletterStatus::Draft, $newsletter->refresh()->status);
    }

    public function test_schedule_newsletter_runs_the_dashboard_content_check(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);
        $newsletter->message->update(['subject' => '']);

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'schedule',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'confirm_live' => true,
        ])->assertHasErrors([SendableContentCheck::SUBJECT_MESSAGE]);

        $this->assertSame(NewsletterStatus::Draft, $newsletter->refresh()->status);
    }

    public function test_schedule_newsletter_unschedules_after_confirmation(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->scheduled()->create();

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'unschedule',
        ])->assertOk()->assertSee(['"applied":false', '"change":"unschedule"']);

        $this->assertSame(NewsletterStatus::Scheduled, $newsletter->refresh()->status);

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'unschedule',
            'confirm_live' => true,
        ])->assertOk()->assertSee('"applied":true');

        $newsletter->refresh();
        $this->assertSame(NewsletterStatus::Draft, $newsletter->status);
        $this->assertNull($newsletter->scheduled_at);
    }

    public function test_schedule_newsletter_refuses_a_sent_newsletter(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'sent']);

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'schedule',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'confirm_live' => true,
        ])->assertHasErrors(["Newsletter #{$newsletter->id} is sent."]);

        $this->assertSame(NewsletterStatus::Sent, $newsletter->refresh()->status);
    }

    public function test_schedule_newsletter_errors_for_another_clients_newsletter(): void
    {
        $user = User::factory()->create();
        $foreign = Newsletter::factory()->create(['status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $foreign->id,
            'action' => 'schedule',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'confirm_live' => true,
        ])->assertHasErrors(["No newsletter found with id {$foreign->id}."]);

        $this->assertSame(NewsletterStatus::Draft, $foreign->refresh()->status);
    }

    public function test_schedule_newsletter_warns_about_a_missing_unsubscribe_link_and_postal_address_and_schedules(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $user->client->update(['postal_address' => null]);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);
        $newsletter->message->update(['html_content' => '<p>No link here</p>', 'text_content' => 'No link here']);
        $sendAt = now()->addDay()->startOfMinute()->toIso8601String();
        $expectedWarnings = [SendableContentCheck::UNSUBSCRIBE_WARNING, UpdateAccountProfileTool::POSTAL_ADDRESS_WARNING];

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'schedule',
            'scheduled_at' => $sendAt,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', false)
            ->where('warnings', $expectedWarnings)
            ->etc());

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'schedule',
            'scheduled_at' => $sendAt,
            'confirm_live' => true,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', true)
            ->where('status', 'scheduled')
            ->where('warnings', $expectedWarnings)
            ->etc());

        $this->assertSame(NewsletterStatus::Scheduled, $newsletter->refresh()->status);
    }

    public function test_schedule_newsletter_returns_no_warnings_for_compliant_content(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'schedule',
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('warnings', [])
            ->etc());
    }

    public function test_set_sequence_step_status_warns_about_a_missing_unsubscribe_link_and_postal_address_and_activates(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $user->client->update(['postal_address' => '']);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'draft']);
        $step->message->update(['html_content' => '<p>Welcome</p>', 'text_content' => 'Welcome']);
        $expectedWarnings = [SendableContentCheck::UNSUBSCRIBE_WARNING, UpdateAccountProfileTool::POSTAL_ADDRESS_WARNING];

        ByoMailerServer::actingAs($user)->tool(SetSequenceStepStatusTool::class, [
            'sequence_step_id' => $step->id,
            'status' => 'live',
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', false)
            ->where('warnings', $expectedWarnings)
            ->etc());

        ByoMailerServer::actingAs($user)->tool(SetSequenceStepStatusTool::class, [
            'sequence_step_id' => $step->id,
            'status' => 'live',
            'confirm_live' => true,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', true)
            ->where('status', 'live')
            ->where('warnings', $expectedWarnings)
            ->etc());

        $this->assertSame(SequenceStatus::Live, $step->refresh()->status);
    }

    public function test_set_sequence_status_warns_about_live_steps_without_an_unsubscribe_link_and_the_postal_address(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $user->client->update(['postal_address' => null]);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $withoutLink = SequenceStep::factory()->for($sequence)->create(['status' => 'live', 'delay_days' => 1]);
        $withoutLink->message->update(['html_content' => '<p>Welcome</p>', 'text_content' => 'Welcome']);
        $draftWithoutLink = SequenceStep::factory()->for($sequence)->create(['status' => 'draft', 'delay_days' => 2]);
        $draftWithoutLink->message->update(['html_content' => '<p>Later</p>', 'text_content' => 'Later']);
        SequenceStep::factory()->for($sequence)->create(['status' => 'live', 'delay_days' => 3]);
        $expectedWarnings = [
            "Step #{$withoutLink->id}: ".SendableContentCheck::UNSUBSCRIBE_WARNING,
            UpdateAccountProfileTool::POSTAL_ADDRESS_WARNING,
        ];

        ByoMailerServer::actingAs($user)->tool(SetSequenceStatusTool::class, [
            'sequence_id' => $sequence->id,
            'status' => 'live',
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', false)
            ->where('warnings', $expectedWarnings)
            ->etc());

        ByoMailerServer::actingAs($user)->tool(SetSequenceStatusTool::class, [
            'sequence_id' => $sequence->id,
            'status' => 'live',
            'confirm_live' => true,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', true)
            ->where('status', 'live')
            ->where('warnings', $expectedWarnings)
            ->etc());

        $this->assertSame(SequenceStatus::Live, $sequence->refresh()->status);
    }

    public function test_status_tool_descriptions_explain_the_warnings(): void
    {
        foreach ([ScheduleNewsletterTool::class, SetSequenceStatusTool::class, SetSequenceStepStatusTool::class] as $tool) {
            $description = app($tool)->description();

            $this->assertStringContainsString('warnings', $description, $tool);
            $this->assertStringContainsString('{{ unsubscribe_url }}', $description, $tool);
            $this->assertStringContainsString('postal address', $description, $tool);
            $this->assertStringContainsString('never block', $description, $tool);
        }
    }

    public function test_server_instructions_describe_the_confirmed_status_change(): void
    {
        $attribute = (new ReflectionClass(ByoMailerServer::class))->getAttributes(Instructions::class)[0];
        $instructions = (string) $attribute->getArguments()[0];

        $this->assertStringContainsString('set-sequence-status', $instructions);
        $this->assertStringContainsString('the first call saves nothing and returns a preview', $instructions);
        $this->assertStringNotContainsString('can never be activated through this API', $instructions);
    }

    public function test_server_instructions_explain_that_compliance_warnings_never_block(): void
    {
        $attribute = (new ReflectionClass(ByoMailerServer::class))->getAttributes(Instructions::class)[0];
        $instructions = (string) $attribute->getArguments()[0];

        $this->assertStringContainsString('warnings', $instructions);
        $this->assertStringContainsString('{{ unsubscribe_url }}', $instructions);
        $this->assertStringContainsString('postal address', $instructions);
    }
}
