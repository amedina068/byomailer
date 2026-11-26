<?php

namespace Tests\Feature\Mcp;

use App\Enums\NewsletterStatus;
use App\Enums\SequenceStatus;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CreateEmailTemplateTool;
use App\Mcp\Tools\EmailTemplateContentTool;
use App\Mcp\Tools\ListEmailTemplatesTool;
use App\Mcp\Tools\UpdateEmailTemplateTool;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailTemplateToolsTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_HTML = '<div>{{ content }}</div><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>';

    public function test_list_email_templates_returns_the_active_templates_of_the_client(): void
    {
        $user = User::factory()->create();
        $teammate = User::factory()->teammateOf($user)->create();
        EmailTemplate::factory()->for($user)->create(['name' => 'Mine']);
        EmailTemplate::factory()->for($teammate)->create(['name' => 'Teammate layout']);
        EmailTemplate::factory()->for($user)->create(['name' => 'Old layout', 'archived_at' => now()]);
        EmailTemplate::factory()->create(['name' => 'Foreign layout']);
        EmailTemplate::factory()->system()->create(['name' => 'Starter layout']);

        ByoMailerServer::actingAs($user)->tool(ListEmailTemplatesTool::class, [])
            ->assertOk()
            ->assertSee(['Mine', 'Teammate layout', '"count":2'])
            ->assertDontSee(['Old layout', 'Foreign layout', 'Starter layout', 'html_content']);
    }

    public function test_list_email_templates_filters_archived_default_and_search(): void
    {
        $user = User::factory()->create();
        EmailTemplate::factory()->for($user)->default()->create(['name' => 'Main layout']);
        EmailTemplate::factory()->for($user)->create(['name' => 'Promo layout']);
        EmailTemplate::factory()->for($user)->create(['name' => 'Old layout', 'archived_at' => now()]);

        ByoMailerServer::actingAs($user)->tool(ListEmailTemplatesTool::class, ['archived' => true])
            ->assertOk()->assertSee(['Old layout', '"count":1'])->assertDontSee('Main layout');

        ByoMailerServer::actingAs($user)->tool(ListEmailTemplatesTool::class, ['is_default' => true])
            ->assertOk()->assertSee(['Main layout', '"count":1'])->assertDontSee('Promo layout');

        ByoMailerServer::actingAs($user)->tool(ListEmailTemplatesTool::class, ['search' => 'promo'])
            ->assertOk()->assertSee(['Promo layout', '"count":1'])->assertDontSee('Main layout');
    }

    public function test_email_template_content_returns_the_html_and_only_the_live_uses(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->for($user)->create(['html_content' => '<div class="brand">{{ content }}</div>']);

        $scheduled = Newsletter::factory()->for($user)->scheduled()->create(['title' => 'Scheduled issue']);
        $draft = Newsletter::factory()->for($user)->create(['title' => 'Draft issue']);
        $sent = Newsletter::factory()->for($user)->create(['title' => 'Sent issue', 'status' => NewsletterStatus::Sent]);
        $liveStep = $this->stepOf(Sequence::factory()->for($user)->create(['name' => 'Welcome flow', 'status' => SequenceStatus::Live]));
        $draftStep = $this->stepOf(Sequence::factory()->for($user)->create(['name' => 'Unused flow', 'status' => SequenceStatus::Draft]));

        foreach ([$scheduled, $draft, $sent, $liveStep, $draftStep] as $record) {
            $this->useTemplate($record, $template);
        }

        ByoMailerServer::actingAs($user)->tool(EmailTemplateContentTool::class, [
            'email_template_id' => $template->id,
        ])->assertOk()
            ->assertSee(['<div class=\\"brand\\">{{ content }}</div>', 'Scheduled issue', 'Welcome flow'])
            ->assertSee(['"type":"newsletter"', "\"id\":{$scheduled->id}", '"type":"sequence_step"', "\"id\":{$liveStep->id}"])
            ->assertDontSee(['Draft issue', 'Sent issue', 'Unused flow']);
    }

    public function test_email_template_content_errors_for_another_clients_template(): void
    {
        $user = User::factory()->create();
        $foreign = EmailTemplate::factory()->create(['html_content' => '<p>secret</p>{{ content }}']);

        ByoMailerServer::actingAs($user)->tool(EmailTemplateContentTool::class, [
            'email_template_id' => $foreign->id,
        ])->assertHasErrors(["No email template found with id {$foreign->id}."])
            ->assertDontSee('secret');
    }

    public function test_create_email_template_saves_the_first_template_as_the_default(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateEmailTemplateTool::class, [
            'name' => 'Brand layout',
            'html_content' => self::VALID_HTML,
        ])->assertOk()->assertSee(['Brand layout', '"is_default":true']);

        $template = EmailTemplate::query()->withoutGlobalScopes()->where('name', 'Brand layout')->firstOrFail();
        $this->assertSame($user->client_id, $template->client_id);
        $this->assertSame($user->id, $template->user_id);
        $this->assertSame(self::VALID_HTML, $template->html_content);
        $this->assertTrue($template->is_default);
    }

    public function test_create_email_template_keeps_the_existing_default(): void
    {
        $user = User::factory()->create();
        $default = EmailTemplate::factory()->for($user)->default()->create();

        ByoMailerServer::actingAs($user)->tool(CreateEmailTemplateTool::class, [
            'name' => 'Second layout',
            'html_content' => self::VALID_HTML,
        ])->assertOk()->assertSee('"is_default":false');

        $this->assertTrue($default->fresh()->is_default);
    }

    public function test_create_email_template_requires_one_content_placeholder(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateEmailTemplateTool::class, [
            'name' => 'Broken layout',
            'html_content' => '<p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
        ])->assertHasErrors(['The template must include a {{ content }} placeholder']);

        $this->assertSame(0, EmailTemplate::query()->withoutGlobalScopes()->where('name', 'Broken layout')->count());
    }

    public function test_create_email_template_asks_for_confirmation_without_an_unsubscribe_link(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateEmailTemplateTool::class, [
            'name' => 'No unsubscribe',
            'html_content' => '<div>{{ content }}</div>',
        ])->assertHasErrors(['Template is missing an unsubscribe link.']);

        $this->assertSame(0, EmailTemplate::query()->withoutGlobalScopes()->where('name', 'No unsubscribe')->count());

        ByoMailerServer::actingAs($user)->tool(CreateEmailTemplateTool::class, [
            'name' => 'No unsubscribe',
            'html_content' => '<div>{{ content }}</div>',
            'confirmed_no_unsubscribe' => true,
        ])->assertOk();

        $this->assertSame(1, EmailTemplate::query()->withoutGlobalScopes()->where('name', 'No unsubscribe')->count());
    }

    public function test_update_email_template_changes_only_the_fields_that_are_sent(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->for($user)->create(['name' => 'Old name', 'html_content' => self::VALID_HTML]);
        $this->useTemplate(Newsletter::factory()->for($user)->create(), $template);

        ByoMailerServer::actingAs($user)->tool(UpdateEmailTemplateTool::class, [
            'email_template_id' => $template->id,
            'name' => 'New name',
        ])->assertOk()->assertSee(['"applied":true', "Email template #{$template->id} updated."]);

        $template = $template->fresh();
        $this->assertSame('New name', $template->name);
        $this->assertSame(self::VALID_HTML, $template->html_content);
    }

    public function test_update_email_template_lets_a_teammate_edit_the_html(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        $template = EmailTemplate::factory()->for($owner)->create();
        $html = '<table><tr><td>{{ content }}</td></tr></table><a href="{{ unsubscribe_url }}">Leave</a>';

        ByoMailerServer::actingAs($teammate)->tool(UpdateEmailTemplateTool::class, [
            'email_template_id' => $template->id,
            'html_content' => $html,
        ])->assertOk();

        $this->assertSame($html, $template->fresh()->html_content);
    }

    public function test_update_email_template_previews_a_change_to_a_live_template_and_saves_it_after_confirmation(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->for($user)->create(['name' => 'Live layout', 'html_content' => self::VALID_HTML]);
        $newsletter = Newsletter::factory()->for($user)->scheduled()->create(['title' => 'Friday issue']);
        $this->useTemplate($newsletter, $template);
        $html = '<div class="v2">{{ content }}</div><a href="{{ unsubscribe_url }}">Leave</a>';

        ByoMailerServer::actingAs($user)->tool(UpdateEmailTemplateTool::class, [
            'email_template_id' => $template->id,
            'name' => 'Live layout v2',
            'html_content' => $html,
        ])->assertOk()
            ->assertSee(['"applied":false', 'Preview only. No change was saved.', 'Friday issue', '"html_content":"replaced"', '"to":"Live layout v2"']);

        $unchanged = $template->fresh();
        $this->assertSame('Live layout', $unchanged->name);
        $this->assertSame(self::VALID_HTML, $unchanged->html_content);

        ByoMailerServer::actingAs($user)->tool(UpdateEmailTemplateTool::class, [
            'email_template_id' => $template->id,
            'name' => 'Live layout v2',
            'html_content' => $html,
            'confirm_live' => true,
        ])->assertOk()->assertSee(['"applied":true', 'Friday issue']);

        $updated = $template->fresh();
        $this->assertSame('Live layout v2', $updated->name);
        $this->assertSame($html, $updated->html_content);
    }

    public function test_update_email_template_treats_a_step_of_a_live_sequence_as_live(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->for($user)->create(['name' => 'Drip layout']);
        $this->useTemplate($this->stepOf(Sequence::factory()->for($user)->create(['status' => SequenceStatus::Live])), $template);

        ByoMailerServer::actingAs($user)->tool(UpdateEmailTemplateTool::class, [
            'email_template_id' => $template->id,
            'name' => 'Drip layout v2',
            'confirm_live' => false,
        ])->assertOk()->assertSee(['"applied":false', '"type":"sequence_step"']);

        $this->assertSame('Drip layout', $template->fresh()->name);
    }

    public function test_update_email_template_rejects_html_without_an_unsubscribe_link(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->for($user)->create(['html_content' => self::VALID_HTML]);

        ByoMailerServer::actingAs($user)->tool(UpdateEmailTemplateTool::class, [
            'email_template_id' => $template->id,
            'html_content' => '<div>{{ content }}</div>',
        ])->assertHasErrors(['Template is missing an unsubscribe link.']);

        $this->assertSame(self::VALID_HTML, $template->fresh()->html_content);
    }

    public function test_update_email_template_needs_a_field_to_change(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->for($user)->create();

        ByoMailerServer::actingAs($user)->tool(UpdateEmailTemplateTool::class, [
            'email_template_id' => $template->id,
        ])->assertHasErrors(['Send name, html_content or both.']);
    }

    public function test_update_email_template_errors_for_another_clients_template(): void
    {
        $user = User::factory()->create();
        $foreign = EmailTemplate::factory()->create(['name' => 'Theirs']);

        ByoMailerServer::actingAs($user)->tool(UpdateEmailTemplateTool::class, [
            'email_template_id' => $foreign->id,
            'name' => 'Hijacked',
            'confirm_live' => true,
        ])->assertHasErrors(["No email template found with id {$foreign->id}."]);

        $this->assertSame('Theirs', $foreign->fresh()->name);
    }

    private function stepOf(Sequence $sequence): SequenceStep
    {
        return SequenceStep::factory()->for($sequence)->create(['status' => SequenceStatus::Live]);
    }

    private function useTemplate(Model $record, EmailTemplate $template): void
    {
        Message::query()->withoutGlobalScopes()
            ->where('messageable_type', $record->getMorphClass())
            ->where('messageable_id', $record->getKey())
            ->update(['email_template_id' => $template->id]);
    }
}
