<?php

namespace Tests\Feature;

use App\Enums\SequenceStatus;
use App\Models\Project;
use App\Models\Sequence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SequenceProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_can_be_attached_and_detached(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $sequence->syncProjects([$project->id]);

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Sequence::class, 'projectable_id' => $sequence->id,
            'project_id' => $project->id,
        ]);

        $sequence->syncProjects([]);

        $this->assertDatabaseMissing('projectables', [
            'projectable_type' => Sequence::class, 'projectable_id' => $sequence->id,
        ]);
    }

    public function test_duplicate_ids_in_sync_payload_do_not_error(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $sequence->syncProjects([$project->id, $project->id]);

        $this->assertSame(1, $sequence->projects()->count());
    }

    public function test_deleting_project_removes_pivot_but_keeps_sequence(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $sequence->syncProjects([$project->id]);

        $project->delete();

        $this->assertDatabaseMissing('projectables', ['project_id' => $project->id]);
        $this->assertDatabaseHas('sequences', ['id' => $sequence->id]);
    }

    public function test_deleting_sequence_removes_pivot_but_keeps_project(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $sequence->syncProjects([$project->id]);

        $sequence->delete();

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Sequence::class, 'projectable_id' => $sequence->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_sync_endpoint_attaches_projects(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('sequences.projects.sync', $sequence), ['project_ids' => [$project->id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Sequence::class, 'projectable_id' => $sequence->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_sync_endpoint_with_empty_array_detaches_all(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $sequence->syncProjects([$project->id]);

        $this->actingAs($user)
            ->post(route('sequences.projects.sync', $sequence), ['project_ids' => []])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Sequence::class, 'projectable_id' => $sequence->id]);
    }

    public function test_sync_endpoint_rejects_cross_client_project(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->create(['user_id' => $user->id]);
        $foreignProject = Project::factory()->create();

        $this->actingAs($user)
            ->post(route('sequences.projects.sync', $sequence), ['project_ids' => [$foreignProject->id]])
            ->assertSessionHasErrors('project_ids.0');

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Sequence::class, 'projectable_id' => $sequence->id]);
    }

    public function test_sync_endpoint_returns_404_for_cross_client_sequence(): void
    {
        $user = User::factory()->create();
        $foreignSequence = Sequence::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('sequences.projects.sync', $foreignSequence->id), ['project_ids' => [$project->id]])
            ->assertNotFound();
    }

    public function test_sync_endpoint_requires_authentication(): void
    {
        $sequence = Sequence::factory()->create();

        $this->post(route('sequences.projects.sync', $sequence), ['project_ids' => []])
            ->assertRedirect(route('login'));
    }

    public function test_index_exposes_projects_catalog_and_project_ids(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $sequence->syncProjects([$project->id]);

        Project::factory()->create();

        $this->actingAs($user)
            ->get(route('sequences.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sequences/Index')
                ->has('projects', 1)
                ->where('projects.0.id', $project->id)
                ->where('projects.0.name', $project->name)
                ->where('sequences.0.project_ids', [$project->id]));
    }

    public function test_store_with_project_ids_creates_assignments(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('sequences.store'), [
                'name' => 'Onboarding',
                'project_ids' => [$project->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $sequence = Sequence::query()->where('name', 'Onboarding')->firstOrFail();

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Sequence::class, 'projectable_id' => $sequence->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_update_omitting_project_ids_preserves_assignments(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $sequence->syncProjects([$project->id]);

        $this->actingAs($user)
            ->patch(route('sequences.update', $sequence->id), ['name' => 'Renamed'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $sequence->fresh()->name);
        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Sequence::class, 'projectable_id' => $sequence->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_update_with_empty_project_ids_detaches_all(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $sequence->syncProjects([$project->id]);

        $this->actingAs($user)
            ->patch(route('sequences.update', $sequence->id), [
                'name' => $sequence->name,
                'project_ids' => [],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Sequence::class, 'projectable_id' => $sequence->id]);
    }

    public function test_update_with_cross_client_project_id_rejects_entire_write(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->create([
            'user_id' => $user->id,
            'name' => 'Original',
        ]);
        $foreignProject = Project::factory()->create();

        $this->actingAs($user)
            ->patch(route('sequences.update', $sequence->id), [
                'name' => 'Renamed',
                'project_ids' => [$foreignProject->id],
            ])
            ->assertSessionHasErrors('project_ids.0');

        $this->assertSame('Original', $sequence->fresh()->name);
        $this->assertDatabaseMissing('projectables', ['projectable_type' => Sequence::class, 'projectable_id' => $sequence->id]);
    }

    public function test_status_endpoint_ignores_project_ids_payload(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->create([
            'user_id' => $user->id,
            'status' => 'live',
        ]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $otherProject = Project::factory()->create(['user_id' => $user->id]);
        $sequence->syncProjects([$project->id]);

        $this->actingAs($user)
            ->patch(route('sequences.status.update', $sequence->id), [
                'status' => 'draft',
                'project_ids' => [$otherProject->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(SequenceStatus::Draft, $sequence->fresh()->status);
        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Sequence::class, 'projectable_id' => $sequence->id,
            'project_id' => $project->id,
        ]);
        $this->assertDatabaseMissing('projectables', [
            'projectable_type' => Sequence::class, 'projectable_id' => $sequence->id,
            'project_id' => $otherProject->id,
        ]);
    }

    public function test_sequence_index_shows_the_sequences_of_the_client_with_their_projects(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create(['client_id' => $userA->client_id]);
        $sequence = Sequence::factory()->create(['user_id' => $userA->id]);
        $project = Project::factory()->create(['user_id' => $userA->id]);
        $sequence->syncProjects([$project->id]);

        $this->actingAs($userB)
            ->get(route('sequences.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sequences/Index')
                ->has('projects', 1)
                ->where('projects.0.id', $project->id)
                ->has('sequences', 1)
                ->where('sequences.0.id', $sequence->id)
                ->where('sequences.0.project_ids', [$project->id]));
    }
}
