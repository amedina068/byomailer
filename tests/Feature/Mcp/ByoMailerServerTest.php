<?php

namespace Tests\Feature\Mcp;

use App\Enums\DelayUnit;
use App\Enums\NewsletterStatus;
use App\Enums\SequenceStatus;
use App\Mail\NewsletterMail;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CalculateNewsletterContactsTool;
use App\Mcp\Tools\CreateNewsletterTool;
use App\Mcp\Tools\CreateSequenceStepTool;
use App\Mcp\Tools\CreateSequenceTool;
use App\Mcp\Tools\CreateTagTool;
use App\Mcp\Tools\ListNewslettersTool;
use App\Mcp\Tools\ListSequencesTool;
use App\Mcp\Tools\NewsletterContentTool;
use App\Mcp\Tools\NewsletterStatsTool;
use App\Mcp\Tools\SendNewsletterTestMailTool;
use App\Mcp\Tools\SendSequenceStepTestMailTool;
use App\Mcp\Tools\SequenceContentTool;
use App\Mcp\Tools\SequenceStatsTool;
use App\Mcp\Tools\SequenceStepContentTool;
use App\Mcp\Tools\SequenceStepStatsTool;
use App\Mcp\Tools\UpdateNewsletterTool;
use App\Mcp\Tools\UpdateSequenceStepTool;
use App\Mcp\Tools\UpdateSequenceTool;
use App\Mcp\Tools\ViewNewsletterFiltersTool;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\EmailTemplate;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\User;
use App\Services\UserMailerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class ByoMailerServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_newsletters_only_returns_the_authenticated_clients_newsletters(): void
    {
        $user = User::factory()->create();
        Newsletter::factory()->for($user)->create(['title' => 'Mine']);
        Newsletter::factory()->create(['title' => 'Someone Else']);

        $response = ByoMailerServer::actingAs($user)->tool(ListNewslettersTool::class, []);

        $response->assertOk()
            ->assertSee('Mine')
            ->assertSee('"count":1')
            ->assertDontSee('Someone Else');
    }

    public function test_newsletter_stats_returns_engagement_metrics(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'sent']);

        $contact = Contact::factory()->create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
        ]);
        ContactMail::factory()
            ->forNewsletter($newsletter)
            ->create([
                'client_id' => $user->client_id,
                'contact_id' => $contact->id,
                'delivered_at' => now(),
                'opened_at' => now(),
                'status' => 'delivered',
            ]);

        $response = ByoMailerServer::actingAs($user)->tool(NewsletterStatsTool::class, [
            'newsletter_id' => $newsletter->id,
        ]);

        $response->assertOk()
            ->assertSee('metrics')
            ->assertSee('"opened":1')
            ->assertSee('"sent":1');
    }

    public function test_newsletter_content_returns_the_message_body(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update(['html_content' => '<p>Hello reader</p>']);

        $response = ByoMailerServer::actingAs($user)->tool(NewsletterContentTool::class, [
            'newsletter_id' => $newsletter->id,
        ]);

        $response->assertOk()->assertSee('Hello reader');
    }

    public function test_newsletter_stats_keep_the_variant_keys_of_an_ab_test(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->withAbTest()->for($user)->create(['status' => 'sent']);

        ByoMailerServer::actingAs($user)->tool(NewsletterStatsTool::class, ['newsletter_id' => $newsletter->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->has('ab_metrics.a')
                ->has('ab_metrics.b')
                ->etc());
    }

    public function test_newsletter_tools_return_an_error_for_another_clients_newsletter(): void
    {
        $user = User::factory()->create();
        $other = Newsletter::factory()->create();

        $response = ByoMailerServer::actingAs($user)->tool(NewsletterStatsTool::class, [
            'newsletter_id' => $other->id,
        ]);

        $response->assertHasErrors();
    }

    public function test_list_sequences_returns_step_and_contact_counts(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Onboarding']);
        SequenceStep::factory()->count(2)->for($sequence)->create();

        $response = ByoMailerServer::actingAs($user)->tool(ListSequencesTool::class, []);

        $response->assertOk()
            ->assertSee('Onboarding')
            ->assertSee('"steps_count":2');
    }

    public function test_list_sequences_tool_filters_the_paused_sequences(): void
    {
        $user = User::factory()->create();
        Sequence::factory()->for($user)->create(['name' => 'Live one', 'status' => 'live']);
        Sequence::factory()->for($user)->create(['name' => 'Paused one', 'status' => 'draft']);

        $response = ByoMailerServer::actingAs($user)->tool(ListSequencesTool::class, ['status' => 'paused']);

        $response->assertOk()
            ->assertSee('Paused one')
            ->assertSee('"status":"draft"')
            ->assertDontSee('Live one')
            ->assertSee('"count":1');
    }

    public function test_sequence_stats_returns_sequence_level_totals_without_steps(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $contact = Contact::factory()->create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
        ]);
        ContactMail::factory()
            ->forSequenceStep($step)
            ->create([
                'client_id' => $user->client_id,
                'contact_id' => $contact->id,
                'delivered_at' => now(),
                'status' => 'delivered',
            ]);

        $response = ByoMailerServer::actingAs($user)->tool(SequenceStatsTool::class, [
            'sequence_id' => $sequence->id,
        ]);

        $response->assertOk()
            ->assertSee('mail_metrics')
            ->assertDontSee('"steps"');
    }

    public function test_sequence_step_stats_returns_step_level_metrics(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $contact = Contact::factory()->create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
        ]);
        ContactMail::factory()
            ->forSequenceStep($step)
            ->create([
                'client_id' => $user->client_id,
                'contact_id' => $contact->id,
                'delivered_at' => now(),
                'status' => 'delivered',
            ]);

        $response = ByoMailerServer::actingAs($user)->tool(SequenceStepStatsTool::class, [
            'sequence_id' => $sequence->id,
            'step_id' => $step->id,
        ]);

        $response->assertOk()
            ->assertSee('"id":'.$step->id)
            ->assertSee('mail_metrics')
            ->assertSee('"delivered":1');
    }

    public function test_sequence_step_stats_errors_for_unknown_step(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();

        $response = ByoMailerServer::actingAs($user)->tool(SequenceStepStatsTool::class, [
            'sequence_id' => $sequence->id,
            'step_id' => 999999,
        ]);

        $response->assertHasErrors();
    }

    public function test_sequence_content_returns_every_step(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Drip']);
        SequenceStep::factory()->for($sequence)->create();

        $response = ByoMailerServer::actingAs($user)->tool(SequenceContentTool::class, [
            'sequence_id' => $sequence->id,
        ]);

        $response->assertOk()
            ->assertSee('Drip')
            ->assertSee('steps');
    }

    public function test_sequence_step_content_returns_a_single_step(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $response = ByoMailerServer::actingAs($user)->tool(SequenceStepContentTool::class, [
            'sequence_id' => $sequence->id,
            'step_id' => $step->id,
        ]);

        $response->assertOk()->assertSee('"id":'.$step->id);
    }

    public function test_sequence_step_content_errors_for_unknown_step(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();

        $response = ByoMailerServer::actingAs($user)->tool(SequenceStepContentTool::class, [
            'sequence_id' => $sequence->id,
            'step_id' => 999999,
        ]);

        $response->assertHasErrors();
    }

    public function test_view_newsletter_filters_returns_schema_and_account_building_blocks(): void
    {
        $user = User::factory()->create();
        Tag::factory()->for($user)->create(['name' => 'vip']);
        MailList::factory()->for($user)->create(['name' => 'Subscribers']);

        $response = ByoMailerServer::actingAs($user)->tool(ViewNewsletterFiltersTool::class, []);

        $response->assertOk()
            ->assertSee('engagement')
            ->assertSee('vip')
            ->assertSee('Subscribers');
    }

    public function test_view_newsletter_filters_lists_the_categories_and_modes(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(ViewNewsletterFiltersTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('schema.categories', ['attributes', 'tags', 'lists', 'topics', 'date', 'engagement', 'location'])
                ->where('schema.mode_categories', ['attributes', 'tags', 'lists', 'topics'])
                ->where('schema.operator_categories', ['date', 'engagement'])
                ->where('schema.modes', ['all', 'any', 'none'])
                ->where('schema.location_modes', ['any', 'none'])
                ->etc());
    }

    public function test_calculate_newsletter_contacts_counts_active_contacts(): void
    {
        $user = User::factory()->create();
        Contact::factory()->count(3)->create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
        ]);
        Contact::factory()->create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
            'unsubscribed_at' => now(),
        ]);

        $response = ByoMailerServer::actingAs($user)->tool(CalculateNewsletterContactsTool::class, []);

        $response->assertOk()->assertSee('"count":3');
    }

    public function test_calculate_newsletter_contacts_respects_tag_filters(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'premium']);

        $tagged = Contact::factory()->create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
        ]);
        $tagged->tags()->attach($tag);

        Contact::factory()->create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
        ]);

        $response = ByoMailerServer::actingAs($user)->tool(CalculateNewsletterContactsTool::class, [
            'filters' => [
                ['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['premium']],
            ],
        ]);

        $response->assertOk()->assertSee('"count":1');
    }

    public function test_create_newsletter_persists_a_draft_with_its_message(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $response = ByoMailerServer::actingAs($user)->tool(CreateNewsletterTool::class, [
            'title' => 'June Update',
            'subject' => 'Hello {{ name }}',
            'html_content' => '<p>Body copy</p>',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertOk()
            ->assertSee('"status":"draft"')
            ->assertSee('June Update');

        $newsletter = Newsletter::query()->where('title', 'June Update')->firstOrFail();

        $this->assertSame(NewsletterStatus::Draft, $newsletter->status);
        $this->assertSame($user->client_id, $newsletter->client_id);
        $this->assertSame('Hello {{ name }}', $newsletter->message->subject);
        $this->assertSame($identity->id, $newsletter->message->email_identity_id);
        $this->assertNotSame('', $newsletter->message->text_content);
    }

    public function test_create_newsletter_rejects_another_clients_email_identity(): void
    {
        $user = User::factory()->create();
        $otherIdentity = EmailIdentity::factory()->create();

        $response = ByoMailerServer::actingAs($user)->tool(CreateNewsletterTool::class, [
            'title' => 'Bad sender',
            'email_identity_id' => $otherIdentity->id,
        ]);

        $response->assertHasErrors();
        $this->assertDatabaseMissing('newsletters', ['title' => 'Bad sender']);
    }

    public function test_update_newsletter_edits_an_existing_draft(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create(['title' => 'Old title']);
        $newsletter->message->update(['subject' => 'Old subject']);

        $response = ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'title' => 'New title',
            'subject' => 'Hello {{ name }}',
            'html_content' => '<p>Fresh body</p>',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertOk()
            ->assertSee('"status":"draft"')
            ->assertSee('New title');

        $newsletter->refresh();

        $this->assertSame(NewsletterStatus::Draft, $newsletter->status);
        $this->assertSame('New title', $newsletter->title);
        $this->assertSame('Hello {{ name }}', $newsletter->message->subject);
        $this->assertStringContainsString('Fresh body', $newsletter->message->html_content);
        $this->assertSame($identity->id, $newsletter->message->email_identity_id);
        $this->assertNotSame('', $newsletter->message->text_content);
    }

    public function test_update_newsletter_only_changes_provided_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create(['title' => 'Keep title']);
        $newsletter->message->update(['subject' => 'Keep subject']);

        $response = ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'subject' => 'Changed subject',
        ]);

        $response->assertOk();

        $newsletter->refresh();

        $this->assertSame('Keep title', $newsletter->title);
        $this->assertSame('Changed subject', $newsletter->message->subject);
    }

    public function test_update_newsletter_rejects_a_sent_newsletter_even_with_confirmation(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create([
            'title' => 'Locked',
            'status' => 'sent',
        ]);

        ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'title' => 'Hijacked',
            'confirm_live' => true,
        ])->assertHasErrors(["Newsletter #{$newsletter->id} is sent and can no longer be edited. Only draft and scheduled newsletters can be updated."]);

        $this->assertSame('Locked', $newsletter->refresh()->title);
    }

    public function test_update_newsletter_asks_for_confirmation_before_editing_a_scheduled_newsletter(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create([
            'title' => 'Scheduled one',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);

        ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'title' => 'Changed',
        ])->assertHasErrors(["Newsletter #{$newsletter->id} is live.", 'call this tool again with confirm_live set to true']);

        $this->assertSame('Scheduled one', $newsletter->refresh()->title);

        ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'title' => 'Changed',
            'confirm_live' => false,
        ])->assertHasErrors(["Newsletter #{$newsletter->id} is live."]);

        $this->assertSame('Scheduled one', $newsletter->refresh()->title);
    }

    public function test_update_newsletter_edits_a_scheduled_newsletter_after_confirmation(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create([
            'title' => 'Scheduled one',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);
        $newsletter->message->update(['subject' => 'Old subject']);

        ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'title' => 'Changed',
            'subject' => 'New subject',
            'confirm_live' => true,
        ])->assertOk()->assertSee(['"status":"scheduled"', 'Scheduled newsletter']);

        $newsletter->refresh();

        $this->assertSame('Changed', $newsletter->title);
        $this->assertSame('New subject', $newsletter->message->subject);
        $this->assertSame(NewsletterStatus::Scheduled, $newsletter->status);
    }

    public function test_update_newsletter_errors_for_another_clients_newsletter(): void
    {
        $user = User::factory()->create();
        $other = Newsletter::factory()->create(['title' => 'Theirs']);

        $response = ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $other->id,
            'title' => 'Hijacked',
        ]);

        $response->assertHasErrors();
        $this->assertSame('Theirs', $other->refresh()->title);
    }

    public function test_update_newsletter_rejects_another_clients_email_identity(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $otherIdentity = EmailIdentity::factory()->create();

        $response = ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'email_identity_id' => $otherIdentity->id,
        ]);

        $response->assertHasErrors();
    }

    public function test_send_newsletter_test_mail_sends_to_the_authenticated_user(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $factory = Mockery::mock(UserMailerFactory::class);
        $factory->shouldReceive('mailerFor')->andReturn(new PendingMail(Mail::mailer()));
        $this->app->instance(UserMailerFactory::class, $factory);

        $newsletter = Newsletter::factory()->for($user)->create();

        $response = ByoMailerServer::actingAs($user)->tool(SendNewsletterTestMailTool::class, [
            'newsletter_id' => $newsletter->id,
        ]);

        $response->assertOk()->assertSee($user->email);
        Mail::assertSent(NewsletterMail::class);
    }

    public function test_send_newsletter_test_mail_errors_without_a_sender_identity(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message->update(['email_identity_id' => null]);

        $response = ByoMailerServer::actingAs($user)->tool(SendNewsletterTestMailTool::class, [
            'newsletter_id' => $newsletter->id,
        ]);

        $response->assertHasErrors();
    }

    public function test_send_newsletter_test_mail_errors_for_another_clients_newsletter(): void
    {
        $user = User::factory()->create();
        $other = Newsletter::factory()->create();

        $response = ByoMailerServer::actingAs($user)->tool(SendNewsletterTestMailTool::class, [
            'newsletter_id' => $other->id,
        ]);

        $response->assertHasErrors();
    }

    public function test_send_sequence_step_test_mail_sends_to_the_authenticated_user(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $factory = Mockery::mock(UserMailerFactory::class);
        $factory->shouldReceive('mailerFor')->andReturn(new PendingMail(Mail::mailer()));
        $this->app->instance(UserMailerFactory::class, $factory);

        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $response = ByoMailerServer::actingAs($user)->tool(SendSequenceStepTestMailTool::class, [
            'sequence_id' => $sequence->id,
            'step_id' => $step->id,
        ]);

        $response->assertOk()->assertSee($user->email);
        Mail::assertSent(NewsletterMail::class);
    }

    public function test_send_sequence_step_test_mail_errors_without_a_sender_identity(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $step->message->update(['email_identity_id' => null]);

        $response = ByoMailerServer::actingAs($user)->tool(SendSequenceStepTestMailTool::class, [
            'sequence_id' => $sequence->id,
            'step_id' => $step->id,
        ]);

        $response->assertHasErrors();
    }

    public function test_send_sequence_step_test_mail_errors_for_another_clients_sequence(): void
    {
        $user = User::factory()->create();
        $otherSequence = Sequence::factory()->create();
        $otherStep = SequenceStep::factory()->for($otherSequence)->create();

        $response = ByoMailerServer::actingAs($user)->tool(SendSequenceStepTestMailTool::class, [
            'sequence_id' => $otherSequence->id,
            'step_id' => $otherStep->id,
        ]);

        $response->assertHasErrors();
    }

    public function test_send_sequence_step_test_mail_errors_when_the_step_belongs_to_another_sequence(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $otherSequence = Sequence::factory()->for($user)->create();
        $otherStep = SequenceStep::factory()->for($otherSequence)->create();

        $response = ByoMailerServer::actingAs($user)->tool(SendSequenceStepTestMailTool::class, [
            'sequence_id' => $sequence->id,
            'step_id' => $otherStep->id,
        ]);

        $response->assertHasErrors();
    }

    public function test_create_sequence_persists_a_draft(): void
    {
        $user = User::factory()->create();

        $response = ByoMailerServer::actingAs($user)->tool(CreateSequenceTool::class, [
            'name' => 'Onboarding drip',
        ]);

        $response->assertOk()
            ->assertSee('"status":"draft"')
            ->assertSee('Onboarding drip');

        $sequence = Sequence::query()->where('name', 'Onboarding drip')->firstOrFail();

        $this->assertSame(SequenceStatus::Draft, $sequence->status);
        $this->assertSame($user->client_id, $sequence->client_id);
    }

    public function test_create_sequence_cannot_be_created_active(): void
    {
        $user = User::factory()->create();

        $response = ByoMailerServer::actingAs($user)->tool(CreateSequenceTool::class, [
            'name' => 'Sneaky live',
            'status' => 'live',
        ]);

        $response->assertOk();

        $this->assertSame(SequenceStatus::Draft, Sequence::query()->where('name', 'Sneaky live')->firstOrFail()->status);
    }

    public function test_create_sequence_step_persists_a_draft_with_its_message(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $response = ByoMailerServer::actingAs($user)->tool(CreateSequenceStepTool::class, [
            'sequence_id' => $sequence->id,
            'delay_type' => 'days',
            'delay_days' => 3,
            'email_identity_id' => $identity->id,
            'subject' => 'Welcome {{ name }}',
            'html_content' => '<p>Step body</p>',
        ]);

        $response->assertOk()->assertSee('"status":"draft"');

        $step = SequenceStep::query()->where('sequence_id', $sequence->id)->firstOrFail();

        $this->assertSame(SequenceStatus::Draft, $step->status);
        $this->assertSame(3, $step->delay_days);
        $this->assertSame(DelayUnit::Days, $step->delay_type);
        $this->assertSame('Welcome {{ name }}', $step->message->subject);
        $this->assertSame($identity->id, $step->message->email_identity_id);
        $this->assertStringContainsString('Step body', $step->message->html_content);
    }

    public function test_create_sequence_step_cannot_be_created_active(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $response = ByoMailerServer::actingAs($user)->tool(CreateSequenceStepTool::class, [
            'sequence_id' => $sequence->id,
            'delay_type' => 'days',
            'delay_days' => 1,
            'email_identity_id' => $identity->id,
            'status' => 'live',
        ]);

        $response->assertOk();

        $this->assertSame(SequenceStatus::Draft, SequenceStep::query()->where('sequence_id', $sequence->id)->firstOrFail()->status);
    }

    public function test_create_sequence_step_errors_for_another_clients_sequence(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $other = Sequence::factory()->create();

        $response = ByoMailerServer::actingAs($user)->tool(CreateSequenceStepTool::class, [
            'sequence_id' => $other->id,
            'delay_type' => 'days',
            'delay_days' => 1,
            'email_identity_id' => $identity->id,
        ]);

        $response->assertHasErrors();
        $this->assertDatabaseMissing('sequence_steps', ['sequence_id' => $other->id]);
    }

    public function test_create_sequence_step_rejects_another_clients_email_identity(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $otherIdentity = EmailIdentity::factory()->create();

        $response = ByoMailerServer::actingAs($user)->tool(CreateSequenceStepTool::class, [
            'sequence_id' => $sequence->id,
            'delay_type' => 'days',
            'delay_days' => 1,
            'email_identity_id' => $otherIdentity->id,
        ]);

        $response->assertHasErrors();
        $this->assertDatabaseMissing('sequence_steps', ['sequence_id' => $sequence->id]);
    }

    public function test_update_sequence_step_edits_an_existing_draft(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create([
            'status' => 'draft',
            'delay_days' => 1,
            'delay_type' => 'days',
        ]);

        $response = ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'delay_type' => 'hours',
            'delay_days' => 6,
            'email_identity_id' => $identity->id,
            'subject' => 'Hello {{ name }}',
            'html_content' => '<p>Fresh step body</p>',
        ]);

        $response->assertOk()->assertSee('"status":"draft"');

        $step->refresh();

        $this->assertSame(SequenceStatus::Draft, $step->status);
        $this->assertSame(6, $step->delay_days);
        $this->assertSame(DelayUnit::Hours, $step->delay_type);
        $this->assertSame('Hello {{ name }}', $step->message->subject);
        $this->assertStringContainsString('Fresh step body', $step->message->html_content);
        $this->assertSame($identity->id, $step->message->email_identity_id);
        $this->assertNotSame('', $step->message->text_content);
    }

    public function test_tools_list_publishes_content_variables_in_the_input_schema_of_the_sequence_and_newsletter_tools(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['per_page' => 100],
        ])->assertOk();

        $tools = collect($response->json('result.tools'))->keyBy('name');

        foreach (['create-sequence-tool', 'update-sequence-tool', 'create-newsletter-tool', 'update-newsletter-tool'] as $name) {
            $this->assertTrue($tools->has($name), "Missing tool {$name}");

            $property = $tools[$name]['inputSchema']['properties']['content_variables'] ?? null;

            $this->assertIsArray($property, "{$name} does not publish content_variables");
            $this->assertSame('object', $property['type']);
            $this->assertNotEmpty($property['description']);
            $this->assertNotContains('content_variables', $tools[$name]['inputSchema']['required'] ?? []);
        }
    }

    public function test_update_sequence_step_keeps_dollar_amounts_when_a_template_wraps_the_content(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $template = EmailTemplate::factory()->for($user)->create([
            'html_content' => '<header>H</header><p>{{ content }}</p>',
        ]);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'draft']);
        $body = '<p>A figure, $70 saved. Unlimited is <strong>$37/year</strong>. Literal \1 and ${1}.</p>';

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'email_template_id' => $template->id,
            'html_content' => $body,
        ])->assertOk();

        $message = $step->refresh()->message;

        $this->assertSame('<header>H</header><p><div data-slot="content">'.$body.'</div></p>', $message->html_content);
        $this->assertSame($body, $message->template_slot_content);
        $this->assertStringContainsString('$70 saved', $message->text_content);
        $this->assertStringContainsString('$37/year', $message->text_content);
    }

    public function test_update_sequence_step_only_changes_provided_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create([
            'status' => 'draft',
            'delay_days' => 4,
            'delay_type' => 'days',
        ]);
        $step->message->update(['subject' => 'Keep subject']);

        $response = ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'delay_days' => 9,
            'delay_type' => 'days',
        ]);

        $response->assertOk();

        $step->refresh();

        $this->assertSame(9, $step->delay_days);
        $this->assertSame('Keep subject', $step->message->subject);
    }

    public function test_update_sequence_step_asks_for_confirmation_before_editing_a_live_step(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create([
            'status' => 'live',
            'delay_days' => 2,
            'delay_type' => 'days',
        ]);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'delay_days' => 5,
            'delay_type' => 'days',
        ])->assertHasErrors(["Sequence step #{$step->id} is live.", 'call this tool again with confirm_live set to true']);

        $this->assertSame(2, $step->refresh()->delay_days);
    }

    public function test_update_sequence_step_edits_a_live_step_after_confirmation(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'status' => 'live',
            'delay_days' => 2,
            'delay_type' => 'days',
        ]);
        $step->message->update(['subject' => 'Old subject']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'delay_days' => 5,
            'delay_type' => 'days',
            'subject' => 'New subject',
            'confirm_live' => true,
        ])->assertOk()->assertSee(['"status":"live"', 'Live step']);

        $step->refresh();

        $this->assertSame(5, $step->delay_days);
        $this->assertSame('New subject', $step->message->subject);
        $this->assertSame(SequenceStatus::Live, $step->status);
    }

    public function test_update_sequence_step_edits_a_paused_step_without_confirmation(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create([
            'status' => SequenceStatus::Draft,
            'delay_days' => 2,
            'delay_type' => 'days',
        ]);
        $step->message->update(['subject' => 'Old subject']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'delay_days' => 4,
            'delay_type' => 'days',
        ])->assertOk()->assertSee('"status":"draft"');

        $this->assertSame(4, $step->refresh()->delay_days);
        $this->assertSame(SequenceStatus::Draft, $step->status);
    }

    public function test_update_sequence_step_errors_for_another_clients_step(): void
    {
        $user = User::factory()->create();
        $other = SequenceStep::factory()->create(['status' => 'draft', 'delay_days' => 3]);

        $response = ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $other->id,
            'delay_days' => 8,
            'delay_type' => 'days',
        ]);

        $response->assertHasErrors();
        $this->assertSame(3, $other->refresh()->delay_days);
    }

    public function test_update_sequence_step_rejects_another_clients_email_identity(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'draft']);
        $otherIdentity = EmailIdentity::factory()->create();

        $response = ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'email_identity_id' => $otherIdentity->id,
        ]);

        $response->assertHasErrors();
    }

    public function test_create_tag_persists_a_tag_for_the_account(): void
    {
        $user = User::factory()->create();

        $response = ByoMailerServer::actingAs($user)->tool(CreateTagTool::class, [
            'name' => 'VIP',
        ]);

        $response->assertOk()->assertSee('VIP');

        $tag = Tag::query()->where('name', 'VIP')->firstOrFail();

        $this->assertSame($user->id, $tag->user_id);
        $this->assertSame($user->client_id, $tag->client_id);
    }

    public function test_create_tag_rejects_a_name_that_a_teammate_already_used(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        Tag::factory()->for($owner)->create(['name' => 'VIP']);

        $response = ByoMailerServer::actingAs($teammate)->tool(CreateTagTool::class, [
            'name' => 'VIP',
        ]);

        $response->assertHasErrors(['The name has already been taken.']);
        $this->assertSame(1, Tag::query()->where('name', 'VIP')->count());
    }

    public function test_create_tag_allows_the_same_name_for_a_different_account(): void
    {
        $owner = User::factory()->create();
        Tag::factory()->for($owner)->create(['name' => 'VIP']);

        $other = User::factory()->create();

        $response = ByoMailerServer::actingAs($other)->tool(CreateTagTool::class, [
            'name' => 'VIP',
        ]);

        $response->assertOk();
        $this->assertSame(2, Tag::query()->withoutGlobalScopes()->where('name', 'VIP')->count());
    }

    public function test_create_sequence_step_saves_step_filters(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        Tag::factory()->for($user)->create(['name' => 'vip']);

        $response = ByoMailerServer::actingAs($user)->tool(CreateSequenceStepTool::class, [
            'sequence_id' => $sequence->id,
            'delay_type' => 'days',
            'delay_days' => 2,
            'email_identity_id' => $identity->id,
            'filters' => [
                ['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']],
            ],
        ]);

        $response->assertOk();

        $step = SequenceStep::query()->where('sequence_id', $sequence->id)->firstOrFail();

        $this->assertCount(1, $step->filters);
        $this->assertSame('tags', $step->filters[0]['category']);
        $this->assertSame(['vip'], $step->filters[0]['values']);
    }

    public function test_create_sequence_step_rejects_a_list_of_another_client_in_the_filters(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $foreignList = MailList::factory()->for(User::factory())->create();

        $response = ByoMailerServer::actingAs($user)->tool(CreateSequenceStepTool::class, [
            'sequence_id' => $sequence->id,
            'delay_type' => 'days',
            'delay_days' => 2,
            'email_identity_id' => $identity->id,
            'filters' => [
                ['connector' => 'and', 'category' => 'lists', 'mode' => 'any', 'values' => [$foreignList->id]],
            ],
        ]);

        $response->assertHasErrors(["The list with ID {$foreignList->id} does not exist."]);

        $this->assertFalse(SequenceStep::query()->where('sequence_id', $sequence->id)->exists());
    }

    public function test_update_sequence_step_replaces_and_clears_step_filters(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create();
        Tag::factory()->for($user)->create(['name' => 'vip']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'status' => 'draft',
            'filters' => [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']]],
        ]);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'filters' => [
                ['connector' => 'and', 'category' => 'lists', 'mode' => 'any', 'values' => [$list->id]],
            ],
        ])->assertOk();

        $step->refresh();
        $this->assertSame('lists', $step->filters[0]['category']);
        $this->assertSame([$list->id], $step->filters[0]['values']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'filters' => [],
        ])->assertOk();

        $this->assertSame([], $step->refresh()->filters);
    }

    public function test_update_sequence_step_keeps_step_filters_when_omitted(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $filters = [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']]];
        $step = SequenceStep::factory()->for($sequence)->create([
            'status' => 'draft',
            'filters' => $filters,
        ]);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'delay_type' => 'days',
            'delay_days' => 5,
        ])->assertOk();

        $this->assertSame($filters, $step->refresh()->filters);
    }

    public function test_update_sequence_step_rejects_an_unknown_filter_category(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'filters' => [['category' => 'nope', 'values' => [1]]],
        ])->assertHasErrors(['The selected filters.0.category is invalid.']);

        $this->assertNull($step->refresh()->filters);
    }

    public function test_sequence_step_content_returns_step_filters(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create([
            'filters' => [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']]],
        ]);

        ByoMailerServer::actingAs($user)->tool(SequenceStepContentTool::class, [
            'sequence_id' => $sequence->id,
            'step_id' => $step->id,
        ])->assertOk()->assertSee('"category":"tags"');
    }

    public function test_create_sequence_saves_sequence_filters(): void
    {
        $user = User::factory()->create();
        Tag::factory()->for($user)->create(['name' => 'vip']);

        $response = ByoMailerServer::actingAs($user)->tool(CreateSequenceTool::class, [
            'name' => 'VIP drip',
            'filters' => [
                ['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']],
            ],
        ]);

        $response->assertOk()->assertSee('"category":"tags"');

        $sequence = Sequence::query()->where('name', 'VIP drip')->firstOrFail();

        $this->assertCount(1, $sequence->filters);
        $this->assertSame('tags', $sequence->filters[0]['category']);
        $this->assertSame(['vip'], $sequence->filters[0]['values']);
    }

    public function test_create_sequence_rejects_a_list_of_another_client_in_the_filters(): void
    {
        $user = User::factory()->create();
        $foreignList = MailList::factory()->for(User::factory())->create();

        ByoMailerServer::actingAs($user)->tool(CreateSequenceTool::class, [
            'name' => 'Foreign list drip',
            'filters' => [
                ['connector' => 'and', 'category' => 'lists', 'mode' => 'any', 'values' => [$foreignList->id]],
            ],
        ])->assertHasErrors(["The list with ID {$foreignList->id} does not exist."]);

        $this->assertFalse(Sequence::query()->where('name', 'Foreign list drip')->exists());
    }

    public function test_create_sequence_rejects_an_unknown_filter_category(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateSequenceTool::class, [
            'name' => 'Bad filters',
            'filters' => [['category' => 'nope', 'values' => [1]]],
        ])->assertHasErrors(['The selected filters.0.category is invalid.']);

        $this->assertFalse(Sequence::query()->where('name', 'Bad filters')->exists());
    }

    public function test_update_sequence_renames_a_draft_and_keeps_filters_when_omitted(): void
    {
        $user = User::factory()->create();
        $filters = [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']]];
        $sequence = Sequence::factory()->for($user)->withFilters($filters)->create([
            'name' => 'Old name',
            'status' => 'draft',
        ]);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'name' => 'New name',
        ])->assertOk()->assertSee('"status":"draft"');

        $sequence->refresh();
        $this->assertSame('New name', $sequence->name);
        $this->assertSame(SequenceStatus::Draft, $sequence->status);
        $this->assertSame($filters, $sequence->filters);
    }

    public function test_update_sequence_replaces_and_clears_filters(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->withFilters([
            ['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']],
        ])->create(['name' => 'Keep me', 'status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'filters' => [
                ['connector' => 'and', 'category' => 'lists', 'mode' => 'any', 'values' => [$list->id]],
            ],
        ])->assertOk();

        $sequence->refresh();
        $this->assertSame('Keep me', $sequence->name);
        $this->assertSame('lists', $sequence->filters[0]['category']);
        $this->assertSame([$list->id], $sequence->filters[0]['values']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'filters' => [],
        ])->assertOk();

        $this->assertSame([], $sequence->refresh()->filters);
    }

    public function test_update_sequence_filters_replace_a_saved_segment(): void
    {
        $user = User::factory()->create();
        $segment = Segment::factory()->create(['client_id' => $user->client_id]);
        Tag::factory()->for($user)->create(['name' => 'vip']);
        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'draft',
            'segment_id' => $segment->id,
        ]);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'filters' => [
                ['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']],
            ],
        ])->assertOk();

        $sequence->refresh();
        $this->assertNull($sequence->segment_id);
        $this->assertSame('tags', $sequence->filters[0]['category']);
    }

    public function test_update_sequence_asks_for_confirmation_before_editing_a_live_sequence(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Live one', 'status' => 'live']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'name' => 'Changed',
            'filters' => [],
        ])->assertHasErrors(["Sequence #{$sequence->id} is live.", 'call this tool again with confirm_live set to true']);

        $this->assertSame('Live one', $sequence->refresh()->name);
    }

    public function test_update_sequence_edits_a_live_sequence_after_confirmation(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Live one', 'status' => 'live']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'name' => 'Changed',
            'confirm_live' => true,
        ])->assertOk()->assertSee(['"status":"live"', 'Live sequence']);

        $this->assertSame('Changed', $sequence->refresh()->name);
        $this->assertSame(SequenceStatus::Live, $sequence->status);
    }

    public function test_update_sequence_edits_a_paused_sequence_without_confirmation(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Paused one', 'status' => SequenceStatus::Draft]);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'name' => 'Changed',
        ])->assertOk()->assertSee('"status":"draft"');

        $this->assertSame('Changed', $sequence->refresh()->name);
    }

    public function test_update_sequence_errors_for_another_clients_sequence(): void
    {
        $user = User::factory()->create();
        $foreign = Sequence::factory()->create(['name' => 'Not yours', 'status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $foreign->id,
            'name' => 'Hijacked',
        ])->assertHasErrors(["No sequence found with id {$foreign->id}."]);

        $this->assertSame('Not yours', $foreign->refresh()->name);
    }

    public function test_update_sequence_rejects_an_unknown_filter_category(): void
    {
        $user = User::factory()->create();
        $filters = [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']]];
        $sequence = Sequence::factory()->for($user)->withFilters($filters)->create(['status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'filters' => [['category' => 'nope', 'values' => [1]]],
        ])->assertHasErrors(['The selected filters.0.category is invalid.']);

        $this->assertSame($filters, $sequence->refresh()->filters);
    }
}
