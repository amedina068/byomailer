<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\Sequence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SequenceProjectApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequences_can_be_filtered_by_project(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $project = Project::factory()->for($user)->create();
        $inProject = Sequence::factory()->for($user)->create(['name' => 'In Project']);
        $inProject->syncProjects([$project->id]);
        Sequence::factory()->for($user)->create(['name' => 'Unassigned']);

        $response = $this->getJson("/api/v1/sequences?project={$project->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'In Project')
            ->assertJsonPath('data.0.project_ids', [$project->id]);
    }

    public function test_project_filter_with_cross_client_project_returns_no_rows(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        Sequence::factory()->for($user)->create();

        $otherUser = User::factory()->create();
        $foreignProject = Project::factory()->for($otherUser)->create();
        $foreignSequence = Sequence::factory()->for($otherUser)->create();
        $foreignSequence->projects()->sync([$foreignProject->id]);

        $response = $this->getJson("/api/v1/sequences?project={$foreignProject->id}");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_project_filter_rejects_non_integer_value(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/sequences?project=abc');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['project']);
    }

    public function test_project_filter_is_retained_across_cursor_pagination(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $project = Project::factory()->for($user)->create();

        $matching = collect(['Alpha', 'Beta', 'Gamma'])->map(
            fn (string $name) => Sequence::factory()->for($user)->create(['name' => $name, 'status' => 'draft']),
        );
        $matching->each(fn (Sequence $sequence) => $sequence->syncProjects([$project->id]));
        Sequence::factory()->for($user)->create(['name' => 'Not In Project', 'status' => 'draft']);

        $firstPage = $this->getJson("/api/v1/sequences?project={$project->id}&per_page=2");

        $firstPage->assertOk()->assertJsonCount(2, 'data');
        $nextCursor = $firstPage->json('meta.next_cursor');
        $this->assertNotNull($nextCursor);

        $secondPage = $this->getJson("/api/v1/sequences?project={$project->id}&per_page=2&cursor={$nextCursor}");

        $secondPage->assertOk()->assertJsonCount(1, 'data');

        $returnedIds = array_merge(
            $firstPage->json('data.*.id'),
            $secondPage->json('data.*.id'),
        );
        $this->assertEqualsCanonicalizing($matching->pluck('id')->all(), $returnedIds);
    }
}
