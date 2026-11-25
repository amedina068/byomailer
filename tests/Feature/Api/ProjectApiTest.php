<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_index_returns_client_projects_with_cursor_meta(): void
    {
        $user = $this->actingUser();
        Project::factory()->count(3)->create(['client_id' => $user->client_id, 'user_id' => $user->id]);
        Project::factory()->create();

        $response = $this->getJson('/api/v1/projects')->assertOk();

        $response->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'uuid', 'name', 'newsletters_count', 'sequences_count', 'automations_count', 'visual_automations_count', 'created_at']],
                'meta' => ['path', 'per_page', 'next_cursor', 'prev_cursor'],
            ]);
    }

    public function test_index_cursor_round_trip_returns_remaining_records(): void
    {
        $user = $this->actingUser();
        Project::factory()->count(5)->create(['client_id' => $user->client_id, 'user_id' => $user->id]);

        $firstPage = $this->getJson('/api/v1/projects?per_page=2')->assertOk();
        $firstIds = collect($firstPage->json('data'))->pluck('id')->all();
        $nextCursor = $firstPage->json('meta.next_cursor');
        $this->assertNotNull($nextCursor);
        $this->assertCount(2, $firstIds);

        $secondPage = $this->getJson('/api/v1/projects?per_page=2&cursor='.$nextCursor)->assertOk();
        $secondIds = collect($secondPage->json('data'))->pluck('id')->all();

        $this->assertCount(2, $secondIds);
        $this->assertEmpty(array_intersect($firstIds, $secondIds));
        $this->assertNotNull($secondPage->json('meta.prev_cursor'));
    }

    public function test_store_creates_project_for_client(): void
    {
        $user = $this->actingUser();

        $response = $this->postJson('/api/v1/projects', ['name' => 'Launch Campaign']);

        $response->assertCreated()->assertJsonPath('data.name', 'Launch Campaign');

        $this->assertDatabaseHas('projects', [
            'name' => 'Launch Campaign',
            'client_id' => $user->client_id,
            'user_id' => $user->id,
        ]);
    }

    public function test_show_returns_project(): void
    {
        $user = $this->actingUser();
        $project = Project::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id]);

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.name', $project->name);
    }

    public function test_update_renames_project(): void
    {
        $user = $this->actingUser();
        $project = Project::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id, 'name' => 'Old Name']);

        $this->patchJson("/api/v1/projects/{$project->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertSame('New Name', $project->fresh()->name);
    }

    public function test_update_allows_keeping_same_name(): void
    {
        $user = $this->actingUser();
        $project = Project::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id, 'name' => 'Same Name']);

        $this->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Same Name'])->assertOk();
    }

    public function test_update_allows_case_only_self_rename(): void
    {
        $user = $this->actingUser();
        $project = Project::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id, 'name' => 'Marketing']);

        $this->patchJson("/api/v1/projects/{$project->id}", ['name' => 'MARKETING'])
            ->assertOk()
            ->assertJsonPath('data.name', 'MARKETING');

        $this->assertSame('MARKETING', $project->fresh()->name);
    }

    public function test_destroy_deletes_project(): void
    {
        $user = $this->actingUser();
        $project = Project::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id]);

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_store_validation_errors(): void
    {
        $user = $this->actingUser();
        Project::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id, 'name' => 'Existing']);

        $this->postJson('/api/v1/projects', [])
            ->assertStatus(422)->assertJsonValidationErrorFor('name');
        $this->postJson('/api/v1/projects', ['name' => str_repeat('a', 256)])
            ->assertStatus(422)->assertJsonValidationErrorFor('name');
        $this->postJson('/api/v1/projects', ['name' => 'Existing'])
            ->assertStatus(422)->assertJsonValidationErrorFor('name');
    }

    public function test_duplicate_name_check_is_case_insensitive(): void
    {
        $user = $this->actingUser();
        Project::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id, 'name' => 'Launch Plan']);

        $this->postJson('/api/v1/projects', ['name' => 'LAUNCH plan'])
            ->assertStatus(422)->assertJsonValidationErrorFor('name');
    }

    public function test_update_rejects_name_taken_by_sibling_project(): void
    {
        $user = $this->actingUser();
        Project::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id, 'name' => 'Taken']);
        $project = Project::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id, 'name' => 'Mine']);

        $this->patchJson("/api/v1/projects/{$project->id}", ['name' => 'taken'])
            ->assertStatus(422)->assertJsonValidationErrorFor('name');
    }

    public function test_name_uniqueness_is_scoped_per_client_not_global(): void
    {
        $otherClientUser = User::factory()->create();
        Project::factory()->create(['client_id' => $otherClientUser->client_id, 'user_id' => $otherClientUser->id, 'name' => 'Shared Name']);

        $this->actingUser();

        $this->postJson('/api/v1/projects', ['name' => 'Shared Name'])->assertCreated();
    }

    public function test_cross_tenant_show_update_destroy_return_404(): void
    {
        $this->actingUser();
        $other = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$other->id}")->assertNotFound();
        $this->patchJson("/api/v1/projects/{$other->id}", ['name' => 'Hijack'])->assertNotFound();
        $this->deleteJson("/api/v1/projects/{$other->id}")->assertNotFound();

        $this->assertDatabaseHas('projects', ['id' => $other->id, 'name' => $other->name]);
    }

    public function test_guest_receives_401(): void
    {
        $this->getJson('/api/v1/projects')->assertUnauthorized();
        $this->postJson('/api/v1/projects', ['name' => 'Nope'])->assertUnauthorized();
    }
}
