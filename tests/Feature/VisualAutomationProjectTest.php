<?php

namespace Tests\Feature;

use App\Models\MailList;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use App\Models\VisualAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class VisualAutomationProjectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validWorkflow(int $listId, int $tagId): array
    {
        return [
            'nodes' => [
                [
                    'id' => 'trigger-1',
                    'type' => 'trigger',
                    'position' => ['x' => 0, 'y' => 0],
                    'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $listId],
                ],
                [
                    'id' => 'action-1',
                    'type' => 'action',
                    'position' => ['x' => 0, 'y' => 280],
                    'data' => ['action_type' => 'add_tag', 'value' => $tagId],
                ],
            ],
            'edges' => [
                ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'action-1'],
            ],
        ];
    }

    public function test_projects_can_be_attached_and_detached(): void
    {
        $user = User::factory()->create();
        $automation = VisualAutomation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $automation->syncProjects([$project->id]);

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => VisualAutomation::class, 'projectable_id' => $automation->id,
            'project_id' => $project->id,
        ]);

        $automation->syncProjects([]);

        $this->assertDatabaseMissing('projectables', [
            'projectable_type' => VisualAutomation::class, 'projectable_id' => $automation->id,
        ]);
    }

    public function test_duplicate_ids_in_sync_payload_do_not_error(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $automation = VisualAutomation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $automation->syncProjects([$project->id, $project->id]);

        $this->assertSame(1, $automation->projects()->count());
    }

    public function test_deleting_project_removes_pivot_but_keeps_visual_automation(): void
    {
        $user = User::factory()->create();
        $automation = VisualAutomation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        $project->delete();

        $this->assertDatabaseMissing('projectables', ['project_id' => $project->id]);
        $this->assertDatabaseHas('visual_automations', ['id' => $automation->id]);
    }

    public function test_deleting_visual_automation_removes_pivot_but_keeps_project(): void
    {
        $user = User::factory()->create();
        $automation = VisualAutomation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        $automation->delete();

        $this->assertDatabaseMissing('projectables', ['projectable_type' => VisualAutomation::class, 'projectable_id' => $automation->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_sync_endpoint_attaches_projects(): void
    {
        $user = User::factory()->create();
        $automation = VisualAutomation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('visual-automations.projects.sync', $automation), ['project_ids' => [$project->id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => VisualAutomation::class, 'projectable_id' => $automation->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_sync_endpoint_with_empty_array_detaches_all(): void
    {
        $user = User::factory()->create();
        $automation = VisualAutomation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        $this->actingAs($user)
            ->post(route('visual-automations.projects.sync', $automation), ['project_ids' => []])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('projectables', ['projectable_type' => VisualAutomation::class, 'projectable_id' => $automation->id]);
    }

    public function test_sync_endpoint_rejects_cross_client_project(): void
    {
        $user = User::factory()->create();
        $automation = VisualAutomation::factory()->create(['user_id' => $user->id]);
        $foreignProject = Project::factory()->create();

        $this->actingAs($user)
            ->post(route('visual-automations.projects.sync', $automation), ['project_ids' => [$foreignProject->id]])
            ->assertSessionHasErrors('project_ids.0');

        $this->assertDatabaseMissing('projectables', ['projectable_type' => VisualAutomation::class, 'projectable_id' => $automation->id]);
    }

    public function test_sync_endpoint_returns_404_for_cross_client_visual_automation(): void
    {
        $user = User::factory()->create();
        $foreignAutomation = VisualAutomation::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('visual-automations.projects.sync', $foreignAutomation->id), ['project_ids' => [$project->id]])
            ->assertNotFound();
    }

    public function test_sync_endpoint_requires_authentication(): void
    {
        $automation = VisualAutomation::factory()->create();

        $this->post(route('visual-automations.projects.sync', $automation), ['project_ids' => []])
            ->assertRedirect(route('login'));
    }

    public function test_index_exposes_projects_catalog_and_project_ids(): void
    {
        $user = User::factory()->create();
        $automation = VisualAutomation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        Project::factory()->create();

        $this->actingAs($user)
            ->get(route('visual-automations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('visual-automations/Index')
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
            ->post(route('visual-automations.store'), [
                'name' => 'Tag new signups',
                'workflow' => $this->validWorkflow($list->id, $tag->id),
                'project_ids' => [$project->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $automation = VisualAutomation::query()->where('name', 'Tag new signups')->firstOrFail();

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => VisualAutomation::class, 'projectable_id' => $automation->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_update_omitting_project_ids_preserves_assignments(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $automation = VisualAutomation::factory()->create([
            'user_id' => $user->id,
            'workflow' => $this->validWorkflow($list->id, $tag->id),
        ]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        $this->actingAs($user)
            ->put(route('visual-automations.update', $automation->id), [
                'name' => 'Renamed',
                'workflow' => $this->validWorkflow($list->id, $tag->id),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $automation->fresh()->name);
        $this->assertDatabaseHas('projectables', [
            'projectable_type' => VisualAutomation::class, 'projectable_id' => $automation->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_update_with_empty_project_ids_detaches_all(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $automation = VisualAutomation::factory()->create([
            'user_id' => $user->id,
            'workflow' => $this->validWorkflow($list->id, $tag->id),
        ]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        $this->actingAs($user)
            ->put(route('visual-automations.update', $automation->id), [
                'name' => $automation->name,
                'workflow' => $this->validWorkflow($list->id, $tag->id),
                'project_ids' => [],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('projectables', ['projectable_type' => VisualAutomation::class, 'projectable_id' => $automation->id]);
    }

    public function test_update_with_cross_client_project_id_rejects_entire_write(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $automation = VisualAutomation::factory()->create([
            'user_id' => $user->id,
            'name' => 'Original',
            'workflow' => $this->validWorkflow($list->id, $tag->id),
        ]);
        $foreignProject = Project::factory()->create();

        $this->actingAs($user)
            ->put(route('visual-automations.update', $automation->id), [
                'name' => 'Renamed',
                'workflow' => $this->validWorkflow($list->id, $tag->id),
                'project_ids' => [$foreignProject->id],
            ])
            ->assertSessionHasErrors('project_ids.0');

        $this->assertSame('Original', $automation->fresh()->name);
        $this->assertDatabaseMissing('projectables', ['projectable_type' => VisualAutomation::class, 'projectable_id' => $automation->id]);
    }

    public function test_status_endpoint_ignores_project_ids_payload(): void
    {
        $user = User::factory()->create();
        $automation = VisualAutomation::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $otherProject = Project::factory()->create(['user_id' => $user->id]);
        $automation->syncProjects([$project->id]);

        $this->actingAs($user)
            ->patch(route('visual-automations.status.update', $automation->id), [
                'status' => VisualAutomation::STATUS_LIVE,
                'project_ids' => [$otherProject->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(VisualAutomation::STATUS_LIVE, $automation->fresh()->status);
        $this->assertDatabaseHas('projectables', [
            'projectable_type' => VisualAutomation::class, 'projectable_id' => $automation->id,
            'project_id' => $project->id,
        ]);
        $this->assertDatabaseMissing('projectables', [
            'projectable_type' => VisualAutomation::class, 'projectable_id' => $automation->id,
            'project_id' => $otherProject->id,
        ]);
    }

    public function test_visual_automation_index_is_client_scoped_so_teammate_sees_automation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create(['client_id' => $userA->client_id]);
        $automation = VisualAutomation::factory()->create(['user_id' => $userA->id]);
        $project = Project::factory()->create(['user_id' => $userA->id]);
        $automation->syncProjects([$project->id]);

        $this->actingAs($userB)
            ->get(route('visual-automations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('visual-automations/Index')
                ->has('projects', 1)
                ->where('projects.0.id', $project->id)
                ->has('automations', 1)
                ->where('automations.0.project_ids', [$project->id]));
    }
}
