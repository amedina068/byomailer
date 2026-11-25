<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\LinkClicksTool;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkClicksToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_the_links_and_then_the_clickers_page_by_page(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $ana = $this->mailFor($user, $newsletter, 'ana@example.com');
        $ben = $this->mailFor($user, $newsletter, 'ben@example.com');
        $this->click($ana, 'black-friday');
        $this->click($ben, 'black-friday');

        ByoMailerServer::actingAs($user)->tool(LinkClicksTool::class, ['newsletter_id' => $newsletter->id])
            ->assertOk()
            ->assertSee(['"key":"name:black-friday"', '"unique_contacts":2']);

        ByoMailerServer::actingAs($user)->tool(LinkClicksTool::class, ['newsletter_id' => $newsletter->id, 'link' => 'name:black-friday', 'limit' => 1])
            ->assertOk()
            ->assertSee(['ana@example.com', '"next_after_id":'.$ana->id])
            ->assertDontSee('ben@example.com');

        ByoMailerServer::actingAs($user)->tool(LinkClicksTool::class, ['newsletter_id' => $newsletter->id, 'link' => 'name:black-friday', 'after_id' => $ana->id])
            ->assertOk()
            ->assertSee(['ben@example.com', '"next_after_id":null'])
            ->assertDontSee('ana@example.com');
    }

    public function test_it_reports_on_a_sequence_step(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $this->click($this->mailFor($user, $step, 'ana@example.com'), 'pricing');

        ByoMailerServer::actingAs($user)->tool(LinkClicksTool::class, ['sequence_id' => $sequence->id, 'step_id' => $step->id])
            ->assertOk()
            ->assertSee('"key":"name:pricing"');
    }

    public function test_it_rejects_a_key_that_is_not_from_the_link_list(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        ByoMailerServer::actingAs($user)->tool(LinkClicksTool::class, ['newsletter_id' => $newsletter->id, 'link' => 'black-friday'])
            ->assertHasErrors(['The link must be a key from the link list, for example "name:black-friday" or "url:https://example.com/sale".']);
    }

    public function test_it_does_not_read_another_client(): void
    {
        $owner = User::factory()->create();
        $newsletter = Newsletter::factory()->for($owner)->create();
        $sequence = Sequence::factory()->for($owner)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $stranger = User::factory()->create();

        ByoMailerServer::actingAs($stranger)->tool(LinkClicksTool::class, ['newsletter_id' => $newsletter->id])
            ->assertHasErrors(["No newsletter found with id {$newsletter->id}."]);

        ByoMailerServer::actingAs($stranger)->tool(LinkClicksTool::class, ['sequence_id' => $sequence->id, 'step_id' => $step->id])
            ->assertHasErrors(["No sequence found with id {$sequence->id}."]);
    }

    private function mailFor(User $user, Newsletter|SequenceStep $mailable, string $email): ContactMail
    {
        return ContactMail::factory()
            ->for(Contact::factory()->for($user)->state(['email' => $email]))
            ->for($mailable, 'mailable')
            ->create(['delivered_at' => now()]);
    }

    private function click(ContactMail $mail, string $linkName): void
    {
        ContactMailClick::factory()->create([
            'contact_mail_id' => $mail->id,
            'contact_id' => $mail->contact_id,
            'link' => 'https://example.com/'.$linkName,
            'link_name' => $linkName,
        ]);
    }
}
