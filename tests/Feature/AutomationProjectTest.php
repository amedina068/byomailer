<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\MailList;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AutomationProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_can_be_attached_and_detached(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $automation->syncProjects([$project->id]);

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Automation::class, 'projectable_id' => $automation->id,
            'project_id' => $project->id,
        ]);

        $automation->syncProjects([]);

        $this->assertDatabaseMissing('projectables', [
            'projectable_type' => Automation::class, 'projectable_id' => $automation->id,
        ]);
    }

    public function test_duplicate_ids_in_sync_payload_do_not_error(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $automation = Automation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $automation->syncProjects([$project->id, $project->id]);

        $this->assertSame(1, $automation->projects()->count());
    }

    public function test_deleting_project_removes_pivot_but_keeps_automation(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        $project->delete();

        $this->assertDatabaseMissing('projectables', ['project_id' => $project->id]);
        $this->assertDatabaseHas('automations', ['id' => $automation->id]);
    }

    public function test_deleting_automation_removes_pivot_but_keeps_project(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        $automation->delete();

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Automation::class, 'projectable_id' => $automation->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_sync_endpoint_attaches_projects(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('automations.projects.sync', $automation), ['project_ids' => [$project->id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Automation::class, 'projectable_id' => $automation->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_sync_endpoint_with_empty_array_detaches_all(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        $this->actingAs($user)
            ->post(route('automations.projects.sync', $automation), ['project_ids' => []])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Automation::class, 'projectable_id' => $automation->id]);
    }

    public function test_sync_endpoint_rejects_cross_client_project(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->create(['user_id' => $user->id]);
        $foreignProject = Project::factory()->create();

        $this->actingAs($user)
            ->post(route('automations.projects.sync', $automation), ['project_ids' => [$foreignProject->id]])
            ->assertSessionHasErrors('project_ids.0');

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Automation::class, 'projectable_id' => $automation->id]);
    }

    public function test_sync_endpoint_returns_404_for_cross_client_automation(): void
    {
        $user = User::factory()->create();
        $foreignAutomation = Automation::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('automations.projects.sync', $foreignAutomation->id), ['project_ids' => [$project->id]])
            ->assertNotFound();
    }

    public function test_sync_endpoint_requires_authentication(): void
    {
        $automation = Automation::factory()->create();

        $this->post(route('automations.projects.sync', $automation), ['project_ids' => []])
            ->assertRedirect(route('login'));
    }

    public function test_index_exposes_projects_catalog_and_project_ids(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        Project::factory()->create();

        $this->actingAs($user)
            ->get(route('automations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('automations/Index')
                ->has('projects', 1)
                ->where('projects.0.id', $project->id)
                ->where('projects.0.name', $project->name)
                ->where('automations.0.project_ids', [$project->id]));
    }

    public function test_store_with_project_ids_creates_assignments(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('automations.store'), [
                'name' => 'Tag new signups',
                'type' => 'simple',
                'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
                'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
                'project_ids' => [$project->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $automation = Automation::query()->where('name', 'Tag new signups')->firstOrFail();

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Automation::class, 'projectable_id' => $automation->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_update_omitting_project_ids_preserves_assignments(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $automation = Automation::factory()->create([
            'user_id' => $user->id,
            'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        $this->actingAs($user)
            ->put(route('automations.update', $automation->id), [
                'name' => 'Renamed',
                'type' => 'simple',
                'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
                'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $automation->fresh()->name);
        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Automation::class, 'projectable_id' => $automation->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_update_with_empty_project_ids_detaches_all(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $automation = Automation::factory()->create([
            'user_id' => $user->id,
            'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        $this->actingAs($user)
            ->put(route('automations.update', $automation->id), [
                'name' => $automation->name,
                'type' => 'simple',
                'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
                'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
                'project_ids' => [],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Automation::class, 'projectable_id' => $automation->id]);
    }

    public function test_update_with_cross_client_project_id_rejects_entire_write(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $automation = Automation::factory()->create([
            'user_id' => $user->id,
            'name' => 'Original',
            'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ]);
        $foreignProject = Project::factory()->create();

        $this->actingAs($user)
            ->put(route('automations.update', $automation->id), [
                'name' => 'Renamed',
                'type' => 'simple',
                'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
                'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
                'project_ids' => [$foreignProject->id],
            ])
            ->assertSessionHasErrors('project_ids.0');

        $this->assertSame('Original', $automation->fresh()->name);
        $this->assertDatabaseMissing('projectables', ['projectable_type' => Automation::class, 'projectable_id' => $automation->id]);
    }

    public function test_status_endpoint_ignores_project_ids_payload(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $otherProject = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        $this->actingAs($user)
            ->patch(route('automations.status.update', $automation->id), [
                'is_live' => false,
                'project_ids' => [$otherProject->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse($automation->fresh()->is_live);
        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Automation::class, 'projectable_id' => $automation->id,
            'project_id' => $project->id,
        ]);
        $this->assertDatabaseMissing('projectables', [
            'projectable_type' => Automation::class, 'projectable_id' => $automation->id,
            'project_id' => $otherProject->id,
        ]);
    }

    public function test_automation_index_shows_the_automations_of_the_client_with_their_projects(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create(['client_id' => $userA->client_id]);
        $automation = Automation::factory()->create(['user_id' => $userA->id]);
        $project = Project::factory()->create(['user_id' => $userA->id]);
        $automation->syncProjects([$project->id]);

        $this->actingAs($userB)
            ->get(route('automations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('automations/Index')
                ->has('projects', 1)
                ->where('projects.0.id', $project->id)
                ->has('automations', 1)
                ->where('automations.0.id', $automation->id)
                ->where('automations.0.project_ids', [$project->id]));
    }
}
