<?php

namespace Tests\Feature;

use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CreateAutomationTool;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use App\Rules\SequenceMustBeLive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The web dashboard and the MCP server save an automation with the same
 * rules and the same value types.
 */
class AutomationWritePathTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_web_automation_saved_with_string_ids_runs_when_the_tag_is_added(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create();

        $this->actingAs($user)->post(route('automations.store'), [
            'name' => 'Tag to list',
            'type' => 'simple',
            'condition' => ['type' => 'tag_added', 'value' => (string) $tag->id],
            'actions' => [['type' => 'add_list', 'value' => (string) $list->id]],
        ])->assertSessionHasNoErrors();

        $this->actingAsTenant($user->client_id);
        $contact = Contact::factory()->for($user)->create();
        $contact->syncTags([$tag->id], true);

        $this->assertTrue($contact->refresh()->lists->contains('id', $list->id));
    }

    public function test_web_and_mcp_store_the_same_value_types(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create();

        $this->actingAs($user)->post(route('automations.store'), [
            'name' => 'Web',
            'type' => 'simple',
            'condition' => ['type' => 'tag_added', 'value' => (string) $tag->id],
            'actions' => [['type' => 'add_list', 'value' => (string) $list->id]],
        ])->assertSessionHasNoErrors();

        ByoMailerServer::actingAs($user)->tool(CreateAutomationTool::class, [
            'name' => 'Mcp',
            'condition' => ['type' => 'tag_added', 'value' => (string) $tag->id],
            'actions' => [['type' => 'add_list', 'value' => $list->id]],
        ])->assertOk();

        $this->actingAsTenant($user->client_id);
        $web = Automation::query()->where('name', 'Web')->sole();
        $mcp = Automation::query()->where('name', 'Mcp')->sole();

        $this->assertSame(['type' => 'tag_added', 'value' => $tag->id], $web->condition);
        $this->assertSame($mcp->condition, $web->condition);
        $this->assertSame([['type' => 'add_list', 'value' => $list->id]], $web->actions);
        $this->assertSame($mcp->actions, $web->actions);
    }

    public function test_web_saves_a_paused_automation_that_adds_contacts_to_a_draft_sequence(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();
        $draft = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $automation = Automation::factory()->for($user)->create([
            'is_live' => false,
            'condition' => ['type' => 'tag_added', 'value' => $tag->id],
            'actions' => [['type' => 'add_sequence', 'value' => $draft->id]],
        ]);

        $this->actingAs($user)->put(route('automations.update', $automation), [
            'name' => 'Renamed',
            'type' => 'simple',
            'condition' => ['type' => 'tag_added', 'value' => (string) $tag->id],
            'actions' => [['type' => 'add_sequence', 'value' => (string) $draft->id]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $automation->refresh()->name);
    }

    public function test_web_refuses_a_draft_sequence_on_a_live_automation(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();
        $draft = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $automation = Automation::factory()->for($user)->create([
            'is_live' => true,
            'condition' => ['type' => 'tag_added', 'value' => $tag->id],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ]);

        $this->actingAs($user)->put(route('automations.update', $automation), [
            'name' => 'Renamed',
            'type' => 'simple',
            'condition' => ['type' => 'tag_added', 'value' => (string) $tag->id],
            'actions' => [['type' => 'add_sequence', 'value' => (string) $draft->id]],
        ])->assertSessionHasErrors(['actions.0.value' => SequenceMustBeLive::MESSAGE]);

        $this->assertSame([['type' => 'add_tag', 'value' => $tag->id]], $automation->refresh()->actions);
    }

    public function test_web_refuses_an_id_that_is_not_a_number(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();

        $this->actingAs($user)->post(route('automations.store'), [
            'name' => 'Bad id',
            'type' => 'simple',
            'condition' => ['type' => 'tag_added', 'value' => 'abc'],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ])->assertSessionHasErrors('condition.value');
    }
}
