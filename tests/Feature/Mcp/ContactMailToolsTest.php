<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\ContactMailDetailsTool;
use App\Mcp\Tools\ExportSequenceStepContactMailsTool;
use App\Mcp\Tools\ListContactMailsTool;
use App\Mcp\Tools\ListSequenceStepContactMailsTool;
use App\Mcp\Tools\SequenceStatsTool;
use App\Mcp\Tools\SequenceStepStatsTool;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContactMailToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tools_are_discoverable_through_the_authenticated_mcp_endpoint(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['per_page' => 50],
        ])->assertOk();

        $tools = collect($response->json('result.tools'))->keyBy('name');

        foreach (['sequence-stats', 'sequence-step-stats', 'list-contact-mails', 'contact-mail-details', 'list-sequence-step-contact-mails', 'export-sequence-step-contact-mails'] as $name) {
            $this->assertTrue($tools->has($name.'-tool'), "Missing tool {$name}-tool");
            $this->assertTrue($tools[$name.'-tool']['annotations']['readOnlyHint']);
        }
    }

    public function test_listing_includes_teammates_mails_from_the_same_client(): void
    {
        $user = User::factory()->create();
        $teammate = User::factory()->create(['client_id' => $user->client_id]);
        $newsletter = Newsletter::factory()->for($teammate)->create();
        $contact = Contact::factory()->for($teammate)->create(['email' => 'teammate@example.com']);
        ContactMail::factory()->for($contact)->forNewsletter($newsletter)->create();

        ByoMailerServer::actingAs($user)->tool(ListContactMailsTool::class)
            ->assertOk()->assertSee('teammate@example.com')->assertSee('"total":1');
    }

    public function test_list_only_returns_mails_from_the_authenticated_client(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create(['email' => 'mine@example.com']);
        ContactMail::factory()->for($contact)->forNewsletter($newsletter)->create();
        ContactMail::factory()->create();

        ByoMailerServer::actingAs($user)->tool(ListContactMailsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('mails', 1)
                ->where('mails.0.contact.email', 'mine@example.com')
                ->where('pagination.total', 1)
                ->etc());
    }

    public function test_listing_filters_engagement_search_and_dates_and_paginates_in_sorted_order(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        foreach (['zeta', 'alpha', 'beta'] as $name) {
            $contact = Contact::factory()->for($user)->create(['email' => $name.'@example.com']);
            ContactMail::factory()->for($contact)->forNewsletter($newsletter)->clicked()->create([
                'subject' => 'Welcome',
                'sent_at' => '2026-08-10 12:00:00',
            ]);
        }
        $other = Contact::factory()->for($user)->create();
        ContactMail::factory()->for($other)->forNewsletter($newsletter)->pending()->create(['subject' => 'Welcome']);

        ByoMailerServer::actingAs($user)->tool(ListContactMailsTool::class, [
            'newsletter_id' => $newsletter->id,
            'filter' => 'opened',
            'search' => 'Welcome',
            'sent_after' => '2026-08-01',
            'sent_before' => '2026-08-31',
            'sort' => 'email',
            'direction' => 'asc',
            'page' => 2,
            'per_page' => 1,
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('mails.0.contact.email', 'beta@example.com')
            ->where('pagination.current_page', 2)
            ->where('pagination.total', 3)
            ->where('pagination.last_page', 3)
            ->where('pagination.has_more_pages', true)
            ->etc());
    }

    public function test_listing_supports_contact_sequence_step_and_exact_status_filters(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $contact = Contact::factory()->for($user)->create();
        $mail = ContactMail::factory()->for($contact)->forSequenceStep($step)->bounced()->create();
        ContactMail::factory()->for($contact)->forSequenceStep()->sent()->create();

        ByoMailerServer::actingAs($user)->tool(ListContactMailsTool::class, [
            'contact_id' => $contact->id,
            'sequence_id' => $sequence->id,
            'step_id' => $step->id,
            'status' => 'bounced',
            'filter' => 'bounced',
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('mails', 1)
            ->where('mails.0.id', $mail->id)
            ->etc());
    }

    public function test_skipped_step_mails_can_be_filtered_and_exported(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $contact = Contact::factory()->for($user)->create();
        $mail = ContactMail::factory()->for($contact)->forSequenceStep($step)->skipped()->create();

        ByoMailerServer::actingAs($user)->tool(ListSequenceStepContactMailsTool::class, [
            'sequence_id' => $sequence->id, 'step_id' => $step->id, 'filter' => 'skipped',
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('mails', 1)->where('mails.0.id', $mail->id)->where('mails.0.status', 'skipped')->etc());

        $response = $this->get(route('sequences.steps.contact-mails.export', [
            'sequence' => $sequence->id, 'sequenceStep' => $step->id, 'filter' => 'skipped',
        ]))->assertOk();
        $this->assertStringContainsString('skipped', $response->streamedContent());
    }

    public function test_listing_searches_contact_name_and_email_and_supports_one_date_boundary(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create(['name' => 'Unique recipient', 'email' => 'recipient@example.com']);
        ContactMail::factory()->for($contact)->forNewsletter($newsletter)->sent()->create();

        foreach (['Unique', 'recipient@example.com'] as $search) {
            ByoMailerServer::actingAs($user)->tool(ListContactMailsTool::class, [
                'search' => $search,
                'sent_before' => now()->addDay()->toIso8601String(),
            ])->assertOk()->assertSee('"total":1');
        }
    }

    public function test_sent_date_filters_are_inclusive_and_normalize_timezone_offsets(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        ContactMail::factory()->for($contact)->forNewsletter($newsletter)->create([
            'sent_at' => '2026-08-10 12:00:00',
        ]);

        ByoMailerServer::actingAs($user)->tool(ListContactMailsTool::class, [
            'sent_after' => '2026-08-10T07:00:00-05:00',
            'sent_before' => '2026-08-10T12:00:00Z',
        ])->assertOk()->assertSee('"total":1');
    }

    public function test_sort_ties_use_mail_id_and_out_of_range_pages_are_empty(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contacts = Contact::factory()->count(2)->for($user)->create();
        $ids = [];
        foreach ($contacts as $contact) {
            $ids[] = ContactMail::factory()->for($contact)->forNewsletter($newsletter)->create(['subject' => 'Same'])->id;
        }

        ByoMailerServer::actingAs($user)->tool(ListContactMailsTool::class, [
            'sort' => 'subject', 'direction' => 'asc', 'per_page' => 1, 'page' => 2,
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('mails.0.id', $ids[1])->etc());

        ByoMailerServer::actingAs($user)->tool(ListContactMailsTool::class, ['page' => 10])
            ->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('mails', 0)->where('pagination.total', 2)->etc());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidFilters(): array
    {
        return [
            'invalid sort' => [['sort' => 'password']],
            'invalid direction' => [['direction' => 'sideways']],
            'invalid status' => [['status' => 'unknown']],
            'invalid filter' => [['filter' => 'unknown']],
            'negative page' => [['page' => 0]],
            'oversized page' => [['per_page' => 101]],
            'invalid date' => [['sent_after' => 'yesterdayish']],
            'reversed dates' => [['sent_after' => '2026-08-31', 'sent_before' => '2026-08-01']],
        ];
    }

    /** @param array<string, mixed> $filters */
    #[DataProvider('invalidFilters')]
    public function test_listing_rejects_invalid_filters(array $filters): void
    {
        ByoMailerServer::actingAs(User::factory()->create())->tool(ListContactMailsTool::class, $filters)
            ->assertHasErrors();
    }

    public function test_step_listing_matches_stats_and_can_include_advanced_and_completed_contacts(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1]);
        $laterStep = SequenceStep::factory()->for($sequence)->create(['delay_days' => 2]);
        $current = Contact::factory()->for($user)->create(['email' => 'current@example.com']);
        $advanced = Contact::factory()->for($user)->create(['email' => 'advanced@example.com']);
        $completed = Contact::factory()->for($user)->create(['email' => 'completed@example.com']);
        $sequence->contacts()->attach($completed, ['completed_at' => now()]);
        foreach ([$current, $advanced, $completed] as $contact) {
            ContactMail::factory()->for($contact)->forSequenceStep($step)->clicked()->create();
        }
        ContactMail::factory()->for($advanced)->forSequenceStep($laterStep)->pending()->create();

        $filters = ['sequence_id' => $sequence->id, 'step_id' => $step->id, 'filter' => 'clicked'];
        ByoMailerServer::actingAs($user)->tool(ListSequenceStepContactMailsTool::class, $filters)
            ->assertOk()->assertSee('current@example.com')->assertDontSee('advanced@example.com')
            ->assertDontSee('completed@example.com')->assertSee('"total":1');
        ByoMailerServer::actingAs($user)->tool(ListSequenceStepContactMailsTool::class, [...$filters, 'current_only' => false])
            ->assertOk()->assertSee('"total":3');
        ByoMailerServer::actingAs($user)->tool(SequenceStepStatsTool::class, $filters)
            ->assertOk()->assertSee('"contacts":1')->assertSee('"clicked":1');
        ByoMailerServer::actingAs($user)->tool(SequenceStatsTool::class, ['sequence_id' => $sequence->id])
            ->assertOk()->assertSee('"total":4');
    }

    public function test_step_tools_reject_unknown_foreign_or_mismatched_steps(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $otherStep = SequenceStep::factory()->create();
        $mismatchedStep = SequenceStep::factory()->for(Sequence::factory()->for($user))->create();

        foreach ([ListSequenceStepContactMailsTool::class, ExportSequenceStepContactMailsTool::class, SequenceStepStatsTool::class] as $tool) {
            foreach ([999999, $otherStep->id, $mismatchedStep->id] as $stepId) {
                ByoMailerServer::actingAs($user)->tool($tool, [
                    'sequence_id' => $sequence->id, 'step_id' => $stepId,
                ])->assertHasErrors();
            }
        }

        ByoMailerServer::actingAs($user)->tool(SequenceStatsTool::class, [
            'sequence_id' => $otherStep->sequence_id,
        ])->assertHasErrors();
    }

    public function test_mail_details_include_content_recipient_events_and_clicks(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $step->message->update(['html_content' => '<p>Step content</p>', 'text_content' => 'Step content']);
        $contact = Contact::factory()->for($user)->create();
        $mail = ContactMail::factory()->for($contact)->forSequenceStep($step)->clicked()->create();
        ContactMailClick::factory()->for($mail)->for($contact)->create(['link' => 'https://example.com/pricing']);

        ByoMailerServer::actingAs($user)->tool(ContactMailDetailsTool::class, ['contact_mail_id' => $mail->id])
            ->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('id', $mail->id)
            ->where('contact.email', $contact->email)
            ->where('sequence_id', $sequence->id)
            ->where('html_content', '<p>Step content</p>')
            ->where('text_content', 'Step content')
            ->where('clicks_count', 1)
            ->where('clicks.0.url', 'https://example.com/pricing')
            ->has('clicked_at')
            ->etc());
    }

    public function test_details_use_the_specific_message_and_bound_click_history(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $mail = ContactMail::factory()->for($contact)->forNewsletter($newsletter)->create([
            'message_id' => $newsletter->message->id,
            'failure_reason' => 'Delivery failed',
            'status' => 'failed',
        ]);
        ContactMailClick::factory()->count(101)->for($mail)->for($contact)->create();

        ByoMailerServer::actingAs($user)->tool(ContactMailDetailsTool::class, ['contact_mail_id' => $mail->id])
            ->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('html_content', $newsletter->message->html_content)
            ->where('failure_reason', 'Delivery failed')
            ->where('clicks_count', 101)
            ->has('clicks', 100)
            ->etc());
    }

    public function test_details_reject_missing_and_foreign_mails_and_lists_do_not_leak_foreign_recipients(): void
    {
        $user = User::factory()->create();
        $foreignMail = ContactMail::factory()->create();
        foreach ([999999, $foreignMail->id] as $id) {
            ByoMailerServer::actingAs($user)->tool(ContactMailDetailsTool::class, ['contact_mail_id' => $id])
                ->assertHasErrors();
        }
        ByoMailerServer::actingAs($user)->tool(ListContactMailsTool::class, [
            'contact_id' => $foreignMail->contact_id,
        ])->assertOk()->assertSee('"total":0');
    }

    public function test_export_tool_returns_the_authenticated_api_download_with_matching_filters(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        ByoMailerServer::actingAs($user)->tool(ExportSequenceStepContactMailsTool::class, [
            'sequence_id' => $sequence->id, 'step_id' => $step->id,
            'filter' => 'clicked', 'current_only' => true, 'sort' => 'email', 'direction' => 'asc',
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('download_url', route('api.v1.sequences.steps.contact-mails.export', [
                'sequenceId' => $sequence->id, 'sequenceStepId' => $step->id,
                'filter' => 'clicked', 'sort' => 'email', 'direction' => 'asc',
            ]))
            ->where('filename', "sequence-{$sequence->id}-step-{$step->id}-contact-mails.csv")
            ->etc());
    }
}
