<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\DeleteProjectTool;
use App\Mcp\Tools\DeleteTagTool;
use App\Mcp\Tools\ListContactAttributesTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTagsTool;
use App\Mcp\Tools\RenameTagTool;
use App\Mcp\Tools\UpdateContactAttributeDefaultTool;
use App\Mcp\Tools\UpdateProjectTool;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\ContactAttribute;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use App\Models\VisualAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagProjectToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_tags_returns_the_tags_of_the_client_with_contact_counts(): void
    {
        $user = User::factory()->create();
        $vip = Tag::factory()->for($user)->create(['name' => 'VIP']);
        Tag::factory()->for($user)->create(['name' => 'Trial']);
        $vip->contacts()->attach(Contact::factory()->for($user)->count(3)->create());
        Tag::factory()->create(['name' => 'Other client tag']);

        ByoMailerServer::actingAs($user)->tool(ListTagsTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('count', 2)
                ->where('tags.0.name', 'Trial')
                ->where('tags.0.contacts_count', 0)
                ->where('tags.1.name', 'VIP')
                ->where('tags.1.contacts_count', 3)
                ->etc())
            ->assertDontSee('Other client tag');
    }

    public function test_rename_tag_changes_the_name(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'VIP']);

        ByoMailerServer::actingAs($user)->tool(RenameTagTool::class, [
            'tag_id' => $tag->id,
            'name' => 'Gold',
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('name', 'Gold')
            ->where('previous_name', 'VIP')
            ->etc());

        $this->assertSame('Gold', $tag->refresh()->name);
    }

    public function test_rename_tag_rejects_a_name_that_another_tag_of_the_client_has(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        Tag::factory()->for($owner)->create(['name' => 'Gold']);
        $tag = Tag::factory()->for($teammate)->create(['name' => 'VIP']);

        ByoMailerServer::actingAs($teammate)->tool(RenameTagTool::class, [
            'tag_id' => $tag->id,
            'name' => 'Gold',
        ])->assertHasErrors(['The name has already been taken.']);

        $this->assertSame('VIP', $tag->refresh()->name);
    }

    public function test_rename_tag_allows_a_name_that_only_another_account_has(): void
    {
        $user = User::factory()->create();
        Tag::factory()->create(['name' => 'Gold']);
        $tag = Tag::factory()->for($user)->create(['name' => 'VIP']);

        ByoMailerServer::actingAs($user)->tool(RenameTagTool::class, [
            'tag_id' => $tag->id,
            'name' => 'Gold',
        ])->assertOk();

        $this->assertSame('Gold', $tag->refresh()->name);
    }

    public function test_rename_tag_refuses_a_tag_that_a_saved_filter_uses(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'VIP']);
        Sequence::factory()->for($user)->create([
            'name' => 'Onboarding',
            'filters' => [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['VIP']]],
        ]);

        ByoMailerServer::actingAs($user)->tool(RenameTagTool::class, [
            'tag_id' => $tag->id,
            'name' => 'Gold',
        ])->assertHasErrors(['Saved filters use this tag: sequence "Onboarding".']);

        $this->assertSame('VIP', $tag->refresh()->name);
    }

    public function test_rename_tag_errors_for_a_tag_of_another_client(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->create(['name' => 'VIP']);

        ByoMailerServer::actingAs($user)->tool(RenameTagTool::class, [
            'tag_id' => $tag->id,
            'name' => 'Gold',
        ])->assertHasErrors(["No tag found with id {$tag->id}."]);

        $this->assertSame('VIP', $tag->refresh()->name);
    }

    public function test_delete_tag_previews_the_contacts_and_the_automations_without_saving(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'VIP']);
        $otherTag = Tag::factory()->for($user)->create(['name' => 'Trial']);
        $tag->contacts()->attach(Contact::factory()->for($user)->count(2)->create());
        $automation = Automation::factory()->for($user)->create([
            'condition' => ['type' => 'removed_tag', 'value' => $tag->id],
        ]);
        Automation::factory()->for($user)->create([
            'condition' => ['type' => 'removed_tag', 'value' => $otherTag->id],
        ]);
        Automation::factory()->for($user)->paused()->create([
            'condition' => ['type' => 'removed_tag', 'value' => $tag->id],
        ]);
        $visualAutomation = VisualAutomation::factory()->for($user)->live()->create([
            'workflow' => [
                'nodes' => [['id' => 'trigger-1', 'type' => 'trigger', 'data' => ['trigger_type' => 'removed_tag', 'value' => $tag->id]]],
                'edges' => [],
            ],
        ]);

        ByoMailerServer::actingAs($user)->tool(DeleteTagTool::class, [
            'tag_id' => $tag->id,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', false)
            ->where('change', 'delete')
            ->where('contacts_count', 2)
            ->where('live_automation_ids', [$automation->id])
            ->where('live_visual_automation_ids', [$visualAutomation->id])
            ->etc());

        $this->assertModelExists($tag);
        $this->assertSame(2, $tag->contacts()->count());
    }

    public function test_delete_tag_removes_the_tag_from_the_contacts_after_confirmation(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'VIP']);
        $keptTag = Tag::factory()->for($user)->create(['name' => 'Trial']);
        $contact = Contact::factory()->for($user)->create();
        $contact->tags()->attach([$tag->id, $keptTag->id]);

        ByoMailerServer::actingAs($user)->tool(DeleteTagTool::class, [
            'tag_id' => $tag->id,
            'confirm_live' => true,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', true)
            ->where('contacts_count', 1)
            ->etc());

        $this->assertModelMissing($tag);
        $this->assertSame([$keptTag->id], $contact->tags()->pluck('tags.id')->all());
    }

    public function test_delete_tag_refuses_a_tag_that_a_saved_filter_uses_even_with_confirmation(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'VIP']);
        Newsletter::factory()->for($user)->create([
            'title' => 'Launch',
            'status' => 'draft',
            'filters' => [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']]],
        ]);

        ByoMailerServer::actingAs($user)->tool(DeleteTagTool::class, [
            'tag_id' => $tag->id,
            'confirm_live' => true,
        ])->assertHasErrors(['Saved filters use this tag: newsletter "Launch".']);

        $this->assertModelExists($tag);
    }

    public function test_delete_tag_errors_for_a_tag_of_another_client(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->create();

        ByoMailerServer::actingAs($user)->tool(DeleteTagTool::class, [
            'tag_id' => $tag->id,
            'confirm_live' => true,
        ])->assertHasErrors(["No tag found with id {$tag->id}."]);

        $this->assertModelExists($tag);
    }

    public function test_list_contact_attributes_returns_the_keys_of_the_client_with_defaults(): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->for($user)->withDefault('friend')->create(['name' => 'nickname']);
        ContactAttribute::factory()->for($user)->create(['name' => 'city']);
        ContactAttribute::factory()->create(['name' => 'other_client_key']);

        ByoMailerServer::actingAs($user)->tool(ListContactAttributesTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('count', 2)
                ->where('attributes.0.name', 'city')
                ->where('attributes.0.default_value', null)
                ->where('attributes.1.name', 'nickname')
                ->where('attributes.1.default_value', 'friend')
                ->etc())
            ->assertDontSee('other_client_key');
    }

    public function test_update_contact_attribute_default_saves_the_value(): void
    {
        $user = User::factory()->create();
        $attribute = ContactAttribute::factory()->for($user)->create(['name' => 'nickname']);

        ByoMailerServer::actingAs($user)->tool(UpdateContactAttributeDefaultTool::class, [
            'attribute_id' => $attribute->id,
            'default_value' => 'friend',
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('default_value', 'friend')
            ->etc());

        $this->assertSame('friend', $attribute->refresh()->default_value);
    }

    public function test_update_contact_attribute_default_clears_the_value_with_an_empty_string(): void
    {
        $user = User::factory()->create();
        $attribute = ContactAttribute::factory()->for($user)->withDefault('friend')->create();

        ByoMailerServer::actingAs($user)->tool(UpdateContactAttributeDefaultTool::class, [
            'attribute_id' => $attribute->id,
            'default_value' => '',
        ])->assertOk();

        $this->assertNull($attribute->refresh()->default_value);
    }

    public function test_update_contact_attribute_default_keeps_a_zero_default(): void
    {
        $user = User::factory()->create();
        $attribute = ContactAttribute::factory()->for($user)->create();

        ByoMailerServer::actingAs($user)->tool(UpdateContactAttributeDefaultTool::class, [
            'attribute_id' => $attribute->id,
            'default_value' => '0',
        ])->assertOk();

        $this->assertSame('0', $attribute->refresh()->default_value);
    }

    public function test_update_contact_attribute_default_requires_a_valid_value(): void
    {
        $user = User::factory()->create();
        $attribute = ContactAttribute::factory()->for($user)->withDefault('friend')->create();

        ByoMailerServer::actingAs($user)->tool(UpdateContactAttributeDefaultTool::class, [
            'attribute_id' => $attribute->id,
        ])->assertHasErrors(['The default value field must be present.']);

        ByoMailerServer::actingAs($user)->tool(UpdateContactAttributeDefaultTool::class, [
            'attribute_id' => $attribute->id,
            'default_value' => str_repeat('a', 256),
        ])->assertHasErrors(['The default value field must not be greater than 255 characters.']);

        $this->assertSame('friend', $attribute->refresh()->default_value);
    }

    public function test_update_contact_attribute_default_errors_for_an_attribute_of_another_client(): void
    {
        $user = User::factory()->create();
        $attribute = ContactAttribute::factory()->withDefault('friend')->create();

        ByoMailerServer::actingAs($user)->tool(UpdateContactAttributeDefaultTool::class, [
            'attribute_id' => $attribute->id,
            'default_value' => 'stranger',
        ])->assertHasErrors(["No contact attribute found with id {$attribute->id}."]);

        $this->assertSame('friend', $attribute->refresh()->default_value);
    }

    public function test_list_projects_returns_the_projects_of_the_client_with_counts(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['name' => 'Launch']);
        Project::factory()->for($user)->create(['name' => 'Retention']);
        Newsletter::factory()->for($user)->count(2)->create()->each->syncProjects([$project->id]);
        Sequence::factory()->for($user)->create()->syncProjects([$project->id]);
        Project::factory()->create(['name' => 'Other client project']);

        ByoMailerServer::actingAs($user)->tool(ListProjectsTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('count', 2)
                ->where('projects.0.name', 'Retention')
                ->where('projects.1.name', 'Launch')
                ->where('projects.1.newsletters_count', 2)
                ->where('projects.1.sequences_count', 1)
                ->where('projects.1.automations_count', 0)
                ->etc())
            ->assertDontSee('Other client project');
    }

    public function test_create_project_saves_the_project_in_the_client_of_the_user(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateProjectTool::class, [
            'name' => 'Launch',
        ])->assertOk()->assertSee('Launch');

        $project = Project::query()->where('name', 'Launch')->firstOrFail();

        $this->assertSame($user->id, $project->user_id);
        $this->assertSame($user->client_id, $project->client_id);
    }

    public function test_create_project_rejects_a_name_of_the_client_in_another_letter_case(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        Project::factory()->for($owner)->create(['name' => 'Launch']);

        ByoMailerServer::actingAs($teammate)->tool(CreateProjectTool::class, [
            'name' => 'LAUNCH',
        ])->assertHasErrors(['A project with this name already exists.']);

        $this->assertSame(1, Project::query()->count());
    }

    public function test_create_project_allows_a_name_that_only_another_account_has(): void
    {
        Project::factory()->create(['name' => 'Launch']);
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateProjectTool::class, [
            'name' => 'Launch',
        ])->assertOk();

        $this->assertSame(2, Project::query()->withoutGlobalScopes()->where('name', 'Launch')->count());
    }

    public function test_update_project_renames_the_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['name' => 'Launch']);

        ByoMailerServer::actingAs($user)->tool(UpdateProjectTool::class, [
            'project_id' => $project->id,
            'name' => 'Launch 2027',
        ])->assertOk();

        $this->assertSame('Launch 2027', $project->refresh()->name);
    }

    public function test_update_project_can_change_the_letter_case_of_its_own_name(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['name' => 'Launch']);

        ByoMailerServer::actingAs($user)->tool(UpdateProjectTool::class, [
            'project_id' => $project->id,
            'name' => 'LAUNCH',
        ])->assertOk();

        $this->assertSame('LAUNCH', $project->refresh()->name);
    }

    public function test_update_project_rejects_a_name_that_another_project_has(): void
    {
        $user = User::factory()->create();
        Project::factory()->for($user)->create(['name' => 'Retention']);
        $project = Project::factory()->for($user)->create(['name' => 'Launch']);

        ByoMailerServer::actingAs($user)->tool(UpdateProjectTool::class, [
            'project_id' => $project->id,
            'name' => 'retention',
        ])->assertHasErrors(['A project with this name already exists.']);

        $this->assertSame('Launch', $project->refresh()->name);
    }

    public function test_update_project_errors_for_a_project_of_another_client(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['name' => 'Launch']);

        ByoMailerServer::actingAs($user)->tool(UpdateProjectTool::class, [
            'project_id' => $project->id,
            'name' => 'Taken over',
        ])->assertHasErrors(["No project found with id {$project->id}."]);

        $this->assertSame('Launch', $project->refresh()->name);
    }

    public function test_delete_project_previews_the_records_without_saving(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Newsletter::factory()->for($user)->count(3)->create()->each->syncProjects([$project->id]);
        Automation::factory()->for($user)->create()->syncProjects([$project->id]);

        ByoMailerServer::actingAs($user)->tool(DeleteProjectTool::class, [
            'project_id' => $project->id,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', false)
            ->where('change', 'delete')
            ->where('newsletters_count', 3)
            ->where('sequences_count', 0)
            ->where('automations_count', 1)
            ->etc());

        $this->assertModelExists($project);
    }

    public function test_delete_project_deletes_the_project_and_keeps_its_records_after_confirmation(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->syncProjects([$project->id]);

        ByoMailerServer::actingAs($user)->tool(DeleteProjectTool::class, [
            'project_id' => $project->id,
            'confirm_live' => true,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', true)
            ->etc());

        $this->assertModelMissing($project);
        $this->assertModelExists($newsletter);
        $this->assertSame(0, $newsletter->projects()->count());
    }

    public function test_delete_project_errors_for_a_project_of_another_client(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        ByoMailerServer::actingAs($user)->tool(DeleteProjectTool::class, [
            'project_id' => $project->id,
            'confirm_live' => true,
        ])->assertHasErrors(["No project found with id {$project->id}."]);

        $this->assertModelExists($project);
    }

    public function test_the_new_tools_use_the_documented_names(): void
    {
        $this->assertSame('list-tags', app(ListTagsTool::class)->name());
        $this->assertSame('rename-tag', app(RenameTagTool::class)->name());
        $this->assertSame('delete-tag', app(DeleteTagTool::class)->name());
        $this->assertSame('list-contact-attributes', app(ListContactAttributesTool::class)->name());
        $this->assertSame('update-contact-attribute-default', app(UpdateContactAttributeDefaultTool::class)->name());
        $this->assertSame('list-projects', app(ListProjectsTool::class)->name());
        $this->assertSame('create-project', app(CreateProjectTool::class)->name());
        $this->assertSame('update-project', app(UpdateProjectTool::class)->name());
        $this->assertSame('delete-project', app(DeleteProjectTool::class)->name());
    }
}
