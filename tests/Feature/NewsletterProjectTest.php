<?php

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NewsletterProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_can_be_attached_and_detached(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $newsletter->syncProjects([$project->id]);

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id,
            'project_id' => $project->id,
        ]);

        $newsletter->syncProjects([]);

        $this->assertDatabaseMissing('projectables', [
            'projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id,
        ]);
    }

    public function test_duplicate_ids_in_sync_payload_do_not_error(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $newsletter->syncProjects([$project->id, $project->id]);

        $this->assertSame(1, $newsletter->projects()->count());
    }

    public function test_deleting_project_removes_pivot_but_keeps_newsletter(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $newsletter->syncProjects([$project->id]);

        $project->delete();

        $this->assertDatabaseMissing('projectables', ['project_id' => $project->id]);
        $this->assertDatabaseHas('newsletters', ['id' => $newsletter->id]);
    }

    public function test_sync_endpoint_attaches_projects(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('newsletters.projects.sync', $newsletter), ['project_ids' => [$project->id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_sync_endpoint_with_empty_array_detaches_all(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $newsletter->syncProjects([$project->id]);

        $this->actingAs($user)
            ->post(route('newsletters.projects.sync', $newsletter), ['project_ids' => []])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id]);
    }

    public function test_sync_endpoint_rejects_cross_client_project(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->create(['user_id' => $user->id]);
        $foreignProject = Project::factory()->create();

        $this->actingAs($user)
            ->post(route('newsletters.projects.sync', $newsletter), ['project_ids' => [$foreignProject->id]])
            ->assertSessionHasErrors('project_ids.0');

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id]);
    }

    public function test_sync_endpoint_returns_404_for_cross_client_newsletter(): void
    {
        $user = User::factory()->create();
        $foreignNewsletter = Newsletter::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('newsletters.projects.sync', $foreignNewsletter->id), ['project_ids' => [$project->id]])
            ->assertNotFound();
    }

    public function test_sync_endpoint_requires_authentication(): void
    {
        $newsletter = Newsletter::factory()->create();

        $this->post(route('newsletters.projects.sync', $newsletter), ['project_ids' => []])
            ->assertRedirect(route('login'));
    }

    public function test_index_exposes_projects_catalog_and_project_ids(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $newsletter->syncProjects([$project->id]);

        Project::factory()->create();

        $this->actingAs($user)
            ->get(route('newsletters.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('newsletters/Index')
                ->has('projects', 1)
                ->where('projects.0.id', $project->id)
                ->where('projects.0.name', $project->name)
                ->where('newsletters.0.project_ids', [$project->id]));
    }

    public function test_deleting_newsletter_removes_pivot_but_keeps_project(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $newsletter->syncProjects([$project->id]);

        $newsletter->delete();

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }
}
