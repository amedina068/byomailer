<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\Sequence;
use App\Models\User;
use App\Models\VisualAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_projects_page_with_projects(): void
    {
        $user = User::factory()->create();
        Project::factory()->count(2)->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Index')
                ->has('projects', 2));
    }

    public function test_index_exposes_resource_counts(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $project->newsletters()->attach(Newsletter::factory()->create(['user_id' => $user->id]));
        $project->sequences()->attach(Sequence::factory()->create(['user_id' => $user->id]));
        $project->automations()->attach(Automation::factory()->create(['user_id' => $user->id]));
        $project->visualAutomations()->attach(VisualAutomation::factory()->create(['user_id' => $user->id]));

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Index')
                ->where('projects.0.newsletters_count', 1)
                ->where('projects.0.sequences_count', 1)
                ->where('projects.0.automations_count', 1)
                ->where('projects.0.visual_automations_count', 1));
    }

    public function test_index_counts_are_zero_for_project_without_assignments(): void
    {
        $user = User::factory()->create();
        Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Index')
                ->where('projects.0.newsletters_count', 0)
                ->where('projects.0.sequences_count', 0)
                ->where('projects.0.automations_count', 0)
                ->where('projects.0.visual_automations_count', 0));
    }

    public function test_destroy_removes_all_pivot_rows_but_keeps_resources(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $newsletter = Newsletter::factory()->create(['user_id' => $user->id]);
        $sequence = Sequence::factory()->create(['user_id' => $user->id]);
        $automation = Automation::factory()->create(['user_id' => $user->id]);
        $visualAutomation = VisualAutomation::factory()->create(['user_id' => $user->id]);

        $project->newsletters()->attach($newsletter);
        $project->sequences()->attach($sequence);
        $project->automations()->attach($automation);
        $project->visualAutomations()->attach($visualAutomation);

        $this->actingAs($user)
            ->delete(route('projects.destroy', $project))
            ->assertRedirect();

        $this->assertDatabaseMissing('projectables', ['project_id' => $project->id]);

        $this->assertDatabaseHas('newsletters', ['id' => $newsletter->id]);
        $this->assertDatabaseHas('sequences', ['id' => $sequence->id]);
        $this->assertDatabaseHas('automations', ['id' => $automation->id]);
        $this->assertDatabaseHas('visual_automations', ['id' => $visualAutomation->id]);
    }

    public function test_store_creates_project_for_clients_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.store'), ['name' => 'Launch Q3'])
            ->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'name' => 'Launch Q3',
            'client_id' => $user->client_id,
            'user_id' => $user->id,
        ]);
    }

    public function test_store_ignores_client_id_in_payload(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.store'), ['name' => 'Sneaky', 'client_id' => $other->client_id])
            ->assertRedirect();

        $this->assertDatabaseHas('projects', ['name' => 'Sneaky', 'client_id' => $user->client_id]);
    }

    public function test_update_renames_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->patch(route('projects.update', $project), ['name' => 'Renamed'])
            ->assertRedirect();

        $this->assertSame('Renamed', $project->fresh()->name);
    }

    public function test_update_allows_keeping_same_name(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Same Name']);

        $this->actingAs($user)
            ->patch(route('projects.update', $project), ['name' => 'Same Name'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_update_allows_case_only_self_rename(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Marketing']);

        $this->actingAs($user)
            ->patch(route('projects.update', $project), ['name' => 'MARKETING'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('MARKETING', $project->fresh()->name);
    }

    public function test_destroy_deletes_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->delete(route('projects.destroy', $project))
            ->assertRedirect();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_teammate_in_same_client_can_update_and_destroy(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create(['client_id' => $owner->client_id]);
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($teammate)
            ->patch(route('projects.update', $project), ['name' => 'Teammate Rename'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($teammate)
            ->delete(route('projects.destroy', $project))
            ->assertRedirect();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_cross_client_update_and_destroy_return_404(): void
    {
        $user = User::factory()->create();
        $foreign = Project::factory()->create();

        $this->actingAs($user)
            ->patch(route('projects.update', $foreign->id), ['name' => 'Hijack'])
            ->assertNotFound();

        $this->actingAs($user)
            ->delete(route('projects.destroy', $foreign->id))
            ->assertNotFound();

        $this->assertDatabaseHas('projects', ['id' => $foreign->id, 'name' => $foreign->name]);
    }

    public function test_name_boundary_255_accepted_256_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.store'), ['name' => str_repeat('a', 255)])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('projects.store'), ['name' => str_repeat('a', 256)])
            ->assertSessionHasErrors('name');
    }

    public function test_whitespace_only_name_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.store'), ['name' => '   '])
            ->assertSessionHasErrors('name');
    }

    public function test_duplicate_name_case_variant_rejected_within_client(): void
    {
        $user = User::factory()->create();
        Project::factory()->create(['user_id' => $user->id, 'name' => 'Marketing']);

        $this->actingAs($user)
            ->post(route('projects.store'), ['name' => 'marketing'])
            ->assertSessionHasErrors('name');
    }

    public function test_duplicate_name_on_update_rejected_within_client(): void
    {
        $user = User::factory()->create();
        Project::factory()->create(['user_id' => $user->id, 'name' => 'Marketing']);
        $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Sales']);

        $this->actingAs($user)
            ->patch(route('projects.update', $project), ['name' => 'MARKETING'])
            ->assertSessionHasErrors('name');
    }

    public function test_same_name_allowed_across_clients(): void
    {
        Project::factory()->create(['name' => 'Marketing']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.store'), ['name' => 'Marketing'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('projects', ['name' => 'Marketing', 'client_id' => $user->client_id]);
    }

    public function test_guest_cannot_access_projects(): void
    {
        $this->get(route('projects.index'))->assertRedirect(route('login'));
    }
}
