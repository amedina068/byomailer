<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LinkClickApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_newsletter_links_lists_each_link_with_its_clicks(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->create();
        $this->click($this->mailFor($user, $newsletter, 'ana@example.com'), 'black-friday');

        $this->getJson("/api/v1/newsletters/{$newsletter->id}/links")
            ->assertOk()
            ->assertJsonPath('data.delivered', 1)
            ->assertJsonPath('data.links.0.key', 'name:black-friday')
            ->assertJsonPath('data.links.0.unique_contacts', 1)
            ->assertJsonPath('data.links.0.click_rate', 100);
    }

    public function test_a_read_only_token_can_read_the_link_report(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['read']);
        $newsletter = Newsletter::factory()->for($user)->create();

        $this->getJson("/api/v1/newsletters/{$newsletter->id}/links")->assertOk();
    }

    public function test_newsletter_link_contacts_are_cursor_paginated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->create();
        $this->click($this->mailFor($user, $newsletter, 'ana@example.com'), 'black-friday');
        $this->click($this->mailFor($user, $newsletter, 'ben@example.com'), 'black-friday');
        $this->click($this->mailFor($user, $newsletter, 'cleo@example.com'), 'pricing');

        $first = $this->getJson("/api/v1/newsletters/{$newsletter->id}/links/contacts?link=name:black-friday&per_page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'ana@example.com')
            ->assertJsonPath('data.0.clicks', 1);

        $this->getJson("/api/v1/newsletters/{$newsletter->id}/links/contacts?link=name:black-friday&per_page=1&cursor=".$first->json('meta.next_cursor'))
            ->assertOk()
            ->assertJsonPath('data.0.email', 'ben@example.com')
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_link_contacts_reject_a_key_that_is_not_from_the_link_list(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->create();

        $this->getJson("/api/v1/newsletters/{$newsletter->id}/links/contacts?link=black-friday")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['link' => 'The link must be a key from the link list.']);
    }

    public function test_newsletter_links_of_another_client_are_not_found(): void
    {
        $newsletter = Newsletter::factory()->for(User::factory()->create())->create();
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson("/api/v1/newsletters/{$newsletter->id}/links")->assertNotFound();
        $this->getJson("/api/v1/newsletters/{$newsletter->id}/links/contacts?link=name:x")->assertNotFound();
    }

    public function test_sequence_step_links_and_contacts(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $this->click($this->mailFor($user, $step, 'ana@example.com'), 'pricing');

        $this->getJson("/api/v1/sequences/{$sequence->id}/steps/{$step->id}/links")
            ->assertOk()
            ->assertJsonPath('data.links.0.key', 'name:pricing');

        $this->getJson("/api/v1/sequences/{$sequence->id}/steps/{$step->id}/links/contacts?link=name:pricing")
            ->assertOk()
            ->assertJsonPath('data.0.email', 'ana@example.com');
    }

    public function test_sequence_step_links_of_another_client_or_sequence_are_not_found(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for(User::factory()->create())->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $ownSequence = Sequence::factory()->for($user)->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/v1/sequences/{$sequence->id}/steps/{$step->id}/links")->assertNotFound();
        $this->getJson("/api/v1/sequences/{$sequence->id}/steps/{$step->id}/links/contacts?link=name:x")->assertNotFound();
        $this->getJson("/api/v1/sequences/{$ownSequence->id}/steps/{$step->id}/links")->assertNotFound();
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
