<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('projects', [
            'id', 'client_id', 'user_id', 'uuid', 'name', 'created_at', 'updated_at',
        ]));
    }

    public function test_factory_creates_project_with_client_id_derived_from_user(): void
    {
        $project = Project::factory()->create();

        $this->assertNotNull($project->client_id);
        $this->assertSame($project->user->client_id, $project->client_id);
        $this->assertNotNull($project->uuid);
    }

    public function test_creating_event_fills_client_id_from_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $project = Project::create(['name' => 'Launch Q3', 'user_id' => $user->id]);

        $this->assertSame($user->client_id, $project->client_id);
    }

    public function test_client_scope_hides_other_clients_projects(): void
    {
        $project = Project::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser);

        $this->assertNull(Project::find($project->id));
        $this->assertNotNull(Project::withoutGlobalScopes()->find($project->id));
    }

    public function test_deleting_client_cascades_projects(): void
    {
        $project = Project::factory()->create();

        Client::withoutGlobalScopes()->find($project->client_id)->delete();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }
}
