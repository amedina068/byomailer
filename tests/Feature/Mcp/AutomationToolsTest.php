<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\AutomationDetailsTool;
use App\Mcp\Tools\CreateAutomationTool;
use App\Mcp\Tools\ListAutomationsTool;
use App\Mcp\Tools\UpdateAutomationTool;
use App\Mcp\Tools\ViewAutomationOptionsTool;
use App\Models\Automation;
use App\Models\ContactAttribute;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use App\Rules\SequenceMustBeLive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_automation_tools_use_the_documented_names(): void
    {
        $this->assertSame('list-automations', app(ListAutomationsTool::class)->name());
        $this->assertSame('automation-details', app(AutomationDetailsTool::class)->name());
        $this->assertSame('view-automation-options', app(ViewAutomationOptionsTool::class)->name());
        $this->assertSame('create-automation', app(CreateAutomationTool::class)->name());
        $this->assertSame('update-automation', app(UpdateAutomationTool::class)->name());
    }

    public function test_list_automations_returns_only_the_users_automations_with_filters(): void
    {
        $user = User::factory()->create();
        Automation::factory()->for($user)->create(['name' => 'Welcome tagger']);
        Automation::factory()->for($user)->paused()->create(['name' => 'Churn cleanup']);
        Automation::factory()->create(['name' => 'Foreign automation']);

        ByoMailerServer::actingAs($user)->tool(ListAutomationsTool::class, [])
            ->assertOk()
            ->assertSee(['Welcome tagger', 'Churn cleanup', '"count":2'])
            ->assertDontSee('Foreign automation');

        ByoMailerServer::actingAs($user)->tool(ListAutomationsTool::class, ['is_live' => false])
            ->assertOk()
            ->assertSee(['Churn cleanup', '"count":1'])
            ->assertDontSee('Welcome tagger');

        ByoMailerServer::actingAs($user)->tool(ListAutomationsTool::class, ['search' => 'welcome'])
            ->assertOk()
            ->assertSee(['Welcome tagger', '"count":1']);
    }

    public function test_automation_details_resolves_names_and_hides_foreign_automations(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['name' => 'Newsletter readers']);
        $tag = Tag::factory()->for($user)->create(['name' => 'Engaged']);
        $automation = Automation::factory()->for($user)->create([
            'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
            'actions' => [
                ['type' => 'add_tag', 'value' => $tag->id],
                ['type' => 'remove_tag', 'value' => 999999],
            ],
        ]);
        $foreign = Automation::factory()->create();

        ByoMailerServer::actingAs($user)->tool(AutomationDetailsTool::class, ['automation_id' => $automation->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('id', $automation->id)
                ->where('condition.name', 'Newsletter readers')
                ->where('actions.0.name', 'Engaged')
                ->where('actions.1.name', null)
                ->etc());

        ByoMailerServer::actingAs($user)->tool(AutomationDetailsTool::class, ['automation_id' => $foreign->id])
            ->assertHasErrors(["No automation found with id {$foreign->id}."]);
    }

    public function test_view_automation_options_lists_the_users_building_blocks(): void
    {
        $user = User::factory()->create();
        MailList::factory()->for($user)->create(['name' => 'Customers']);
        Tag::factory()->for($user)->create(['name' => 'VIP']);
        Sequence::factory()->for($user)->create(['name' => 'Onboarding', 'status' => 'live']);
        ContactAttribute::factory()->for($user)->create(['name' => 'plan']);
        Tag::factory()->create(['name' => 'Foreign tag']);

        ByoMailerServer::actingAs($user)->tool(ViewAutomationOptionsTool::class, [])
            ->assertOk()
            ->assertSee(['Customers', 'VIP', 'Onboarding', 'plan', 'attribute_changed', 'add_sequence'])
            ->assertDontSee('Foreign tag');
    }

    public function test_create_automation_saves_a_paused_automation_with_integer_ids(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();

        ByoMailerServer::actingAs($user)->tool(CreateAutomationTool::class, [
            'name' => 'Tag new subscribers',
            'condition' => ['type' => 'contact_added_to_list', 'value' => (string) $list->id, 'extra' => 'dropped'],
            'actions' => [['type' => 'add_tag', 'value' => (string) $tag->id]],
            'is_live' => true,
        ])->assertOk()->assertSee(['Tag new subscribers', '"is_live":false']);

        $automation = Automation::query()->sole();
        $this->assertSame($user->id, $automation->user_id);
        $this->assertSame($user->client_id, $automation->client_id);
        $this->assertSame(Automation::TYPE_SIMPLE, $automation->type);
        $this->assertFalse($automation->is_live);
        $this->assertSame(['type' => 'contact_added_to_list', 'value' => $list->id], $automation->condition);
        $this->assertSame([['type' => 'add_tag', 'value' => $tag->id]], $automation->actions);
    }

    public function test_create_automation_refuses_the_multistep_type_that_never_runs(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();

        ByoMailerServer::actingAs($user)->tool(CreateAutomationTool::class, [
            'name' => 'Multistep',
            'type' => 'multistep',
            'condition' => ['type' => 'contact_added_to_list', 'value' => (string) $list->id],
            'actions' => [['type' => 'add_tag', 'value' => (string) $tag->id]],
        ])->assertHasErrors(['The selected type is invalid.']);

        $this->assertSame(0, Automation::query()->count());
    }

    public function test_create_automation_normalizes_an_attribute_condition(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();
        ContactAttribute::factory()->for($user)->create(['name' => 'plan']);

        ByoMailerServer::actingAs($user)->tool(CreateAutomationTool::class, [
            'name' => 'Plan upgrade',
            'condition' => ['type' => 'attribute_changed', 'attribute' => 'plan', 'operator' => 'changed_to', 'value' => '  pro  '],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ])->assertOk();

        $this->assertSameJsonData(
            ['type' => 'attribute_changed', 'attribute' => 'plan', 'operator' => 'changed_to', 'value' => 'pro'],
            Automation::query()->sole()->condition,
        );
    }

    public function test_create_automation_rejects_foreign_ids(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $foreignTag = Tag::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateAutomationTool::class, [
            'name' => 'Bad tag',
            'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
            'actions' => [['type' => 'add_tag', 'value' => $foreignTag->id]],
        ])->assertHasErrors(['Tag action value is invalid.']);

        $this->assertSame(0, Automation::query()->count());
    }

    public function test_create_automation_accepts_a_draft_sequence_because_it_is_created_paused(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $draft = Sequence::factory()->for($user)->create(['status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(CreateAutomationTool::class, [
            'name' => 'Draft sequence',
            'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
            'actions' => [['type' => 'add_sequence', 'value' => $draft->id]],
        ])->assertOk()->assertSee(['"is_live":false', 'set-automation-status']);

        $automation = Automation::query()->sole();
        $this->assertFalse($automation->is_live);
        $this->assertSame([['type' => 'add_sequence', 'value' => $draft->id]], $automation->actions);
    }

    public function test_update_automation_accepts_a_draft_sequence_on_a_paused_automation(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $draft = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $automation = Automation::factory()->for($user)->paused()->create([
            'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
        ]);

        ByoMailerServer::actingAs($user)->tool(UpdateAutomationTool::class, [
            'automation_id' => $automation->id,
            'actions' => [['type' => 'add_sequence', 'value' => $draft->id]],
        ])->assertOk();

        $this->assertSame([['type' => 'add_sequence', 'value' => $draft->id]], $automation->refresh()->actions);
    }

    public function test_update_automation_rejects_a_draft_sequence_on_a_live_automation(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $draft = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $automation = Automation::factory()->for($user)->create([
            'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
        ]);
        $actions = $automation->actions;

        ByoMailerServer::actingAs($user)->tool(UpdateAutomationTool::class, [
            'automation_id' => $automation->id,
            'actions' => [['type' => 'add_sequence', 'value' => $draft->id]],
            'confirm_live' => true,
        ])->assertHasErrors([SequenceMustBeLive::MESSAGE]);

        $this->assertSame($actions, $automation->refresh()->actions);
    }

    public function test_create_automation_rejects_a_non_integer_action_value(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        ByoMailerServer::actingAs($user)->tool(CreateAutomationTool::class, [
            'name' => 'Array value',
            'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
            'actions' => [['type' => 'add_tag', 'value' => 'abc']],
        ])->assertHasErrors(['must be an integer']);

        $this->assertSame(0, Automation::query()->count());
    }

    public function test_update_automation_changes_only_passed_fields_of_a_paused_automation(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $otherTag = Tag::factory()->for($user)->create();
        $automation = Automation::factory()->for($user)->paused()->create([
            'name' => 'Old name',
            'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ]);

        ByoMailerServer::actingAs($user)->tool(UpdateAutomationTool::class, [
            'automation_id' => $automation->id,
            'actions' => [['type' => 'remove_tag', 'value' => $otherTag->id]],
        ])->assertOk()->assertSee('"is_live":false');

        $automation->refresh();
        $this->assertSame('Old name', $automation->name);
        $this->assertSame(['type' => 'contact_added_to_list', 'value' => $list->id], $automation->condition);
        $this->assertSame([['type' => 'remove_tag', 'value' => $otherTag->id]], $automation->actions);
        $this->assertFalse($automation->is_live);
    }

    public function test_update_automation_edits_a_live_automation_only_after_confirmation(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $live = Automation::factory()->for($user)->create([
            'name' => 'Live one',
            'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ]);

        ByoMailerServer::actingAs($user)->tool(UpdateAutomationTool::class, [
            'automation_id' => $live->id,
            'name' => 'Renamed',
        ])->assertHasErrors(["Automation #{$live->id} is live.", 'call this tool again with confirm_live set to true']);

        $this->assertSame('Live one', $live->refresh()->name);

        ByoMailerServer::actingAs($user)->tool(UpdateAutomationTool::class, [
            'automation_id' => $live->id,
            'name' => 'Renamed',
            'confirm_live' => true,
        ])->assertOk()->assertSee(['"is_live":true', 'Live automation']);

        $live->refresh();

        $this->assertSame('Renamed', $live->name);
        $this->assertTrue($live->is_live);
    }

    public function test_update_automation_refuses_foreign_automations(): void
    {
        $user = User::factory()->create();
        $foreign = Automation::factory()->paused()->create(['name' => 'Foreign one']);

        ByoMailerServer::actingAs($user)->tool(UpdateAutomationTool::class, [
            'automation_id' => $foreign->id,
            'name' => 'Renamed',
        ])->assertHasErrors(["No automation found with id {$foreign->id}."]);

        $this->assertSame('Foreign one', $foreign->refresh()->name);
    }
}
