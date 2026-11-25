<?php

namespace Tests\Feature;

use App\Enums\SequenceStatus;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CreateSequenceStepTool;
use App\Mcp\Tools\UpdateSequenceStepTool;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\Message;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The web dashboard and the MCP server create, update and delete a sequence
 * step through the same Actions, so they apply the same rules.
 */
class SequenceStepWritePathTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_refuses_a_blank_subject_on_a_live_step(): void
    {
        [$user, $sequence, $step, $identity] = $this->liveStep();
        $subject = $step->message->subject;

        $this->actingAs($user)->patch($this->updateUrl($sequence, $step), [
            'delay_days' => 1,
            'delay_type' => 'days',
            'subject' => '',
            'content' => '<p>Body</p>',
            'email_identity_id' => $identity->id,
        ])->assertSessionHasErrors(['subject' => 'Add a subject before you send.']);

        $this->assertSame($subject, $step->message->refresh()->subject);
    }

    public function test_web_refuses_blank_content_on_a_live_step(): void
    {
        [$user, $sequence, $step, $identity] = $this->liveStep();
        $html = $step->message->html_content;

        $this->actingAs($user)->patch($this->updateUrl($sequence, $step), [
            'delay_days' => 1,
            'delay_type' => 'days',
            'subject' => 'Hello',
            'content' => '<p> </p>',
            'email_identity_id' => $identity->id,
        ])->assertSessionHasErrors(['content' => 'Add content before you send.']);

        $this->assertSame($html, $step->message->refresh()->html_content);
    }

    public function test_web_still_saves_a_blank_draft_step(): void
    {
        [$user, $sequence, $step, $identity] = $this->liveStep();
        $step->update(['status' => 'draft']);

        $this->actingAs($user)->patch($this->updateUrl($sequence, $step), [
            'delay_days' => 1,
            'delay_type' => 'days',
            'subject' => '',
            'content' => '',
            'email_identity_id' => $identity->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull($step->message->refresh()->subject);
    }

    public function test_web_refuses_unsafe_html_in_the_step_content(): void
    {
        [$user, $sequence, $step, $identity] = $this->liveStep();

        $this->actingAs($user)->patch($this->updateUrl($sequence, $step), [
            'delay_days' => 1,
            'delay_type' => 'days',
            'subject' => 'Hello',
            'content' => '<p>Hi</p><script>alert(1)</script>',
            'email_identity_id' => $identity->id,
        ])->assertSessionHasErrors('content');
    }

    public function test_web_refuses_step_content_over_500_kb(): void
    {
        [$user, $sequence, $step, $identity] = $this->liveStep();

        $this->actingAs($user)->patch($this->updateUrl($sequence, $step), [
            'delay_days' => 1,
            'delay_type' => 'days',
            'subject' => 'Hello',
            'content' => '<p>'.str_repeat('a', 524288).'</p>',
            'email_identity_id' => $identity->id,
        ])->assertSessionHasErrors('content');
    }

    public function test_web_store_uses_the_sequence_author_name_when_the_identity_has_no_name(): void
    {
        $user = User::factory()->create(['name' => 'Ada Author']);
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create(['name' => null]);

        $this->actingAs($user)->post(route('sequences.steps.store', $sequence), [
            'delay_days' => 1,
            'delay_type' => 'days',
            'email_identity_id' => $identity->id,
        ])->assertRedirect();

        $this->assertSame('Ada Author', $sequence->steps()->sole()->message->from_name);
    }

    public function test_web_update_uses_the_sequence_author_name_when_the_identity_has_no_name(): void
    {
        [$user, $sequence, $step] = $this->liveStep();
        $user->update(['name' => 'Ada Author']);
        $nameless = EmailIdentity::factory()->for($user)->create(['name' => null]);

        $this->actingAs($user)->patch($this->updateUrl($sequence, $step), [
            'delay_days' => 1,
            'delay_type' => 'days',
            'subject' => 'Hello',
            'content' => '<p>Body</p>',
            'email_identity_id' => $nameless->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Ada Author', $step->message->refresh()->from_name);
    }

    public function test_the_step_message_records_the_acting_user_as_its_author(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create(['client_id' => $owner->client_id]);
        $sequence = Sequence::factory()->for($owner)->create();
        $identity = EmailIdentity::factory()->for($owner)->create();

        $this->actingAs($teammate)->post(route('sequences.steps.store', $sequence), [
            'delay_days' => 1,
            'delay_type' => 'days',
            'email_identity_id' => $identity->id,
        ])->assertRedirect();

        $this->assertSame($teammate->id, $sequence->steps()->sole()->message->user_id);
    }

    public function test_mcp_refuses_a_blank_subject_on_a_live_step_even_when_confirmed(): void
    {
        [$user, , $step] = $this->liveStep();
        $subject = $step->message->subject;

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'subject' => '',
            'confirm_live' => true,
        ])->assertHasErrors(['Add a subject before you send.']);

        $this->assertSame($subject, $step->message->refresh()->subject);
    }

    public function test_mcp_create_still_accepts_a_blank_draft_step(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        ByoMailerServer::actingAs($user)->tool(CreateSequenceStepTool::class, [
            'sequence_id' => $sequence->id,
            'delay_type' => 'days',
            'delay_days' => 1,
            'email_identity_id' => $identity->id,
        ])->assertOk();

        $this->assertSame(SequenceStatus::Draft, $sequence->steps()->sole()->status);
    }

    public function test_web_delete_rolls_back_every_write_when_one_write_fails(): void
    {
        [$user, $sequence, $step] = $this->liveStep();
        $contact = Contact::factory()->for($user)->create();
        $pending = ContactMail::factory()->pending()->for($contact)->forSequenceStep($step)->create();

        Message::deleting(function (): void {
            throw new RuntimeException('The disk is full.');
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->delete(route('sequences.steps.destroy', [
                'sequence' => $sequence,
                'sequenceStep' => $step,
            ]));
            $this->fail('The delete did not fail.');
        } catch (RuntimeException) {
            // The delete failed in the middle.
        }

        $this->assertDatabaseHas('contact_mails', ['id' => $pending->id]);
        $this->assertDatabaseHas('sequence_steps', ['id' => $step->id]);
    }

    /**
     * @return array{0: User, 1: Sequence, 2: SequenceStep, 3: EmailIdentity}
     */
    private function liveStep(): array
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $identity = EmailIdentity::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);

        return [$user, $sequence, $step, $identity];
    }

    private function updateUrl(Sequence $sequence, SequenceStep $step): string
    {
        return route('sequences.steps.update', ['sequence' => $sequence, 'sequenceStep' => $step]);
    }
}
