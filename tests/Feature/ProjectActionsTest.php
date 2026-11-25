<?php

namespace Tests\Feature;

use App\Actions\CreateProject;
use App\Actions\DeleteProject;
use App\Actions\UpdateProject;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_puts_the_project_in_the_client_of_the_user_and_records_the_author(): void
    {
        $user = User::factory()->create();

        $project = (new CreateProject)->handle($user, 'Launch Q3');

        $this->assertTrue($project->exists);
        $this->assertSame('Launch Q3', $project->name);
        $this->assertSame($user->client_id, $project->client_id);
        $this->assertSame($user->id, $project->user_id);
    }

    public function test_update_renames_the_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Old']);

        $updated = (new UpdateProject)->handle($project, 'New');

        $this->assertSame('New', $updated->name);
        $this->assertSame('New', $project->fresh()->name);
    }

    public function test_delete_removes_the_project_and_its_pivot_rows_but_keeps_the_newsletters(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $newsletter = Newsletter::factory()->create(['user_id' => $user->id]);
        $project->newsletters()->attach($newsletter);

        (new DeleteProject)->handle($project);

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('projectables', ['project_id' => $project->id]);
        $this->assertDatabaseHas('newsletters', ['id' => $newsletter->id]);
    }

    public function test_web_writes_go_through_the_actions(): void
    {
        $user = User::factory()->create();
        $this->expectEachActionOnce();

        $this->actingAs($user)->post(route('projects.store'), ['name' => 'Web'])->assertRedirect();
        $project = Project::query()->where('name', 'Web')->sole();
        $this->actingAs($user)->patch(route('projects.update', $project), ['name' => 'Web 2'])->assertRedirect();
        $this->actingAs($user)->delete(route('projects.destroy', $project))->assertRedirect();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_api_writes_go_through_the_actions(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $this->expectEachActionOnce();

        $projectId = $this->postJson('/api/v1/projects', ['name' => 'Api'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/projects/{$projectId}", ['name' => 'Api 2'])->assertOk()->assertJsonPath('data.name', 'Api 2');
        $this->deleteJson("/api/v1/projects/{$projectId}")->assertNoContent();

        $this->assertDatabaseMissing('projects', ['id' => $projectId]);
    }

    private function expectEachActionOnce(): void
    {
        foreach ([CreateProject::class, UpdateProject::class, DeleteProject::class] as $action) {
            $this->partialMock($action)->shouldReceive('handle')->once()->passthru();
        }
    }
}
