<?php

namespace Tests\Feature;

use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CreateAutomationTool;
use App\Mcp\Tools\ViewAutomationOptionsTool;
use App\Models\Automation;
use App\Models\Newsletter;
use App\Models\Tag;
use App\Models\User;
use App\Models\VisualAutomation;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LinkClickedTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_saves_a_link_clicked_automation_with_a_normalized_name(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();

        $this->actingAs($user)->post(route('automations.store'), [
            'name' => 'Tag buyers',
            'type' => 'simple',
            'condition' => ['type' => 'link_clicked', 'value' => '  Black Friday '],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['type' => 'link_clicked', 'value' => 'black-friday'],
            Automation::query()->where('name', 'Tag buyers')->sole()->condition,
        );
    }

    public function test_store_rejects_a_link_name_with_no_letter_or_digit(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();

        $this->actingAs($user)->post(route('automations.store'), [
            'name' => 'Tag buyers',
            'type' => 'simple',
            'condition' => ['type' => 'link_clicked', 'value' => '!!!'],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ])->assertSessionHasErrors(['condition.value' => 'The link name must contain a letter or a digit.']);
    }

    public function test_store_rejects_a_link_name_that_is_too_long(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();

        $this->actingAs($user)->post(route('automations.store'), [
            'name' => 'Tag buyers',
            'type' => 'simple',
            'condition' => ['type' => 'link_clicked', 'value' => str_repeat('a', 101)],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ])->assertSessionHasErrors(['condition.value' => 'The link name must be 100 characters or fewer.']);
    }

    public function test_mcp_create_automation_accepts_a_link_clicked_condition(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();

        ByoMailerServer::actingAs($user)->tool(CreateAutomationTool::class, [
            'name' => 'Pricing clickers',
            'condition' => ['type' => 'link_clicked', 'value' => 'Pricing Page'],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ])->assertOk();

        $this->assertSame(
            ['type' => 'link_clicked', 'value' => 'pricing-page'],
            Automation::query()->sole()->condition,
        );
    }

    public function test_mcp_automation_options_describe_the_link_trigger_and_list_the_link_names(): void
    {
        $user = User::factory()->create();
        $this->newsletterWithHtml($user, '<a href="https://example.com" data-bm-link="Pricing">x</a>');

        ByoMailerServer::actingAs($user)->tool(ViewAutomationOptionsTool::class, [])
            ->assertOk()
            ->assertSee(['link_clicked', 'data-bm-link', '"link_names":["pricing"]']);
    }

    public function test_automation_forms_receive_the_link_names_of_the_client(): void
    {
        $user = User::factory()->create();
        $teammate = User::factory()->teammateOf($user)->create();
        $this->newsletterWithHtml($user, '<a href="https://example.com" data-bm-link="Pricing">x</a>');
        $this->newsletterWithHtml($teammate, '<a href="https://example.com/sale" data-bm-link="black-friday">y</a>');
        $this->newsletterWithHtml(User::factory()->create(), '<a href="https://example.com" data-bm-link="foreign">z</a>');

        $this->actingAs($user)->get(route('automations.create'))
            ->assertInertia(fn (Assert $page) => $page->where('linkNames', ['black-friday', 'pricing']));

        $this->actingAs($user)->get(route('visual-automations.create'))
            ->assertInertia(fn (Assert $page) => $page->where('linkNames', ['black-friday', 'pricing']));
    }

    public function test_visual_automation_accepts_a_link_trigger(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('visual-automations.store'), ['name' => 'Clicked', 'workflow' => $this->workflow('black-friday', $tag->id)])
            ->assertSessionHasNoErrors();

        $this->assertSame('black-friday', VisualAutomation::query()->sole()->triggerNode()['data']['value']);
    }

    public function test_visual_automation_rejects_text_in_a_node_that_takes_an_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('visual-automations.store'), ['name' => 'Broken', 'workflow' => $this->workflow('black-friday', 'black-friday')])
            ->assertSessionHasErrors(['workflow.nodes.1.data.value' => 'The value must be an id.']);
    }

    public function test_visual_automation_rejects_a_link_name_with_no_letter_or_digit(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('visual-automations.store'), ['name' => 'Broken', 'workflow' => $this->workflow('!!!', $tag->id)])
            ->assertSessionHasErrors(['workflow.nodes.0.data.value' => 'Enter a link name with a letter or a digit, 100 characters or fewer.']);
    }

    private function newsletterWithHtml(User $user, string $html): void
    {
        Tenant::run($user->client_id, fn () => Newsletter::factory()->for($user)->create()->message->update(['html_content' => $html]));
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(string $linkName, int|string $actionValue): array
    {
        return [
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'link_clicked', 'value' => $linkName]],
                ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 100], 'data' => ['action_type' => 'add_tag', 'value' => $actionValue]],
            ],
            'edges' => [['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'action-1']],
        ];
    }
}
