<?php

namespace Tests\Feature;

use App\Enums\SequenceStatus;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailIdentityDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private EmailIdentity $identity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->identity = EmailIdentity::factory()->for($this->user)->create();
    }

    private function attachMessage(Model $messageable): void
    {
        Message::factory()->for($this->user)->create([
            'client_id' => $this->user->client_id,
            'email_identity_id' => $this->identity->id,
            'messageable_type' => $messageable::class,
            'messageable_id' => $messageable->getKey(),
        ]);
    }

    private function newsletter(string $status): Newsletter
    {
        $newsletter = Newsletter::factory()->for($this->user)->create(['status' => $status]);
        $this->attachMessage($newsletter);

        return $newsletter;
    }

    private function sequenceStep(SequenceStatus|string $sequenceStatus, SequenceStatus|string $stepStatus): Sequence
    {
        $sequence = Sequence::factory()->for($this->user)->create(['status' => $sequenceStatus]);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => $stepStatus]);
        $this->attachMessage($step);

        return $sequence;
    }

    private function list(bool $optIn): MailList
    {
        $list = MailList::factory()->for($this->user)->create(['opt_in_enabled' => $optIn]);
        $this->attachMessage($list);

        return $list;
    }

    public function test_show_page_lists_the_live_usage_of_the_identity(): void
    {
        $scheduled = $this->newsletter('scheduled');
        $this->newsletter('draft');
        $this->newsletter('sent');
        $sequence = $this->sequenceStep('live', 'live');
        $this->sequenceStep('draft', 'draft');
        $list = $this->list(optIn: true);
        $this->list(optIn: false);

        $response = $this->actingAs($this->user)->get(route('identities.show', $this->identity));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/identities/Show')
            ->has('usage.newsletters', 1)
            ->where('usage.newsletters.0.id', $scheduled->id)
            ->where('usage.newsletters.0.name', $scheduled->title)
            ->has('usage.sequences', 1)
            ->where('usage.sequences.0.id', $sequence->id)
            ->where('usage.sequences.0.name', $sequence->name)
            ->has('usage.lists', 1)
            ->where('usage.lists.0.id', $list->id)
            ->where('usage.lists.0.name', $list->name)
        );
    }

    public function test_destroy_is_blocked_while_a_scheduled_newsletter_uses_the_identity(): void
    {
        $this->newsletter('scheduled');

        $this->assertDestroyIsBlocked();
    }

    public function test_destroy_is_blocked_while_a_sending_newsletter_uses_the_identity(): void
    {
        $this->newsletter('queued');

        $this->assertDestroyIsBlocked();
    }

    public function test_destroy_is_blocked_while_a_live_sequence_step_uses_the_identity(): void
    {
        $this->sequenceStep('live', 'live');

        $this->assertDestroyIsBlocked();
    }

    public function test_destroy_is_blocked_while_a_paused_sequence_has_a_live_step_with_the_identity(): void
    {
        $this->sequenceStep(SequenceStatus::Draft, SequenceStatus::Live);

        $this->assertDestroyIsBlocked();
    }

    public function test_destroy_is_blocked_while_a_list_opt_in_email_uses_the_identity(): void
    {
        $this->list(optIn: true);

        $this->assertDestroyIsBlocked();
    }

    public function test_destroy_is_allowed_when_only_drafts_and_sent_items_use_the_identity(): void
    {
        $this->newsletter('draft');
        $this->newsletter('sent');
        $this->sequenceStep('live', 'draft');
        $this->list(optIn: false);

        $response = $this->actingAs($this->user)->delete(route('identities.destroy', $this->identity));

        $response->assertRedirect(route('identities.index'));
        $this->assertDatabaseMissing('email_identities', ['id' => $this->identity->id]);
    }

    private function assertDestroyIsBlocked(): void
    {
        $response = $this->actingAs($this->user)
            ->from(route('identities.show', $this->identity))
            ->delete(route('identities.destroy', $this->identity));

        $response->assertRedirect(route('identities.show', $this->identity));
        $response->assertSessionHasErrors(['identity' => 'Live newsletters, sequences or list opt-in emails send from this email identity. Change their sender or stop them, then remove the email identity.']);
        $this->assertDatabaseHas('email_identities', ['id' => $this->identity->id]);
    }
}
