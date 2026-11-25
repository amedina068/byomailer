<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SequenceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequences_can_be_listed_scoped_to_the_client(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Onboarding']);
        SequenceStep::factory()->count(2)->for($sequence)->create();
        Sequence::factory()->create(['name' => 'Someone Else']);

        $response = $this->getJson('/api/v1/sequences');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Onboarding')
            ->assertJsonPath('data.0.steps_count', 2)
            ->assertJsonPath('data.0.contacts_count', 0);
    }

    public function test_sequences_count_the_completed_and_the_active_contacts(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->create();
        $sequence->contacts()->attach(Contact::factory()->for($user)->create(), ['completed_at' => now()]);
        $sequence->contacts()->attach(Contact::factory()->for($user)->count(2)->create());

        $this->getJson('/api/v1/sequences')
            ->assertOk()
            ->assertJsonPath('data.0.contacts_count', 3)
            ->assertJsonPath('data.0.completed_count', 1)
            ->assertJsonPath('data.0.active_count', 2);

        $this->getJson("/api/v1/sequences/{$sequence->id}")
            ->assertOk()
            ->assertJsonPath('data.contacts_count', 3)
            ->assertJsonPath('data.completed_count', 1)
            ->assertJsonPath('data.active_count', 2);
    }

    public function test_sequences_can_be_filtered_by_status(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        Sequence::factory()->for($user)->create(['name' => 'Live one', 'status' => 'live']);
        Sequence::factory()->for($user)->create(['name' => 'Draft one', 'status' => 'draft']);

        $response = $this->getJson('/api/v1/sequences?status=live');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Live one');
    }

    public function test_the_paused_status_filter_returns_the_paused_sequences(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        Sequence::factory()->for($user)->create(['name' => 'Live one', 'status' => 'live']);
        Sequence::factory()->for($user)->create(['name' => 'Paused one', 'status' => 'draft']);

        $this->getJson('/api/v1/sequences?status=paused')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Paused one');
    }

    public function test_sequence_content_is_returned_with_steps(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Drip']);
        SequenceStep::factory()->for($sequence)->create();

        $response = $this->getJson("/api/v1/sequences/{$sequence->id}");

        $response->assertOk()
            ->assertJsonPath('data.name', 'Drip')
            ->assertJsonCount(1, 'data.steps')
            ->assertJsonStructure(['data' => ['steps' => [['id', 'message' => ['html_content']]]]]);
    }

    public function test_sequence_stats_returns_sequence_level_totals_without_steps(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $contact = Contact::factory()->create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
        ]);
        ContactMail::factory()
            ->forSequenceStep($step)
            ->create([
                'client_id' => $user->client_id,
                'contact_id' => $contact->id,
                'delivered_at' => now(),
                'status' => 'delivered',
            ]);

        $response = $this->getJson("/api/v1/sequences/{$sequence->id}/stats");

        $response->assertOk()
            ->assertJsonPath('data.id', $sequence->id)
            ->assertJsonPath('data.mail_metrics.delivered', 1)
            ->assertJsonMissingPath('data.steps');
    }

    public function test_sequence_step_stats_returns_step_level_metrics(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $contact = Contact::factory()->create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
        ]);
        ContactMail::factory()
            ->forSequenceStep($step)
            ->create([
                'client_id' => $user->client_id,
                'contact_id' => $contact->id,
                'delivered_at' => now(),
                'status' => 'delivered',
            ]);

        $response = $this->getJson("/api/v1/sequences/{$sequence->id}/steps/{$step->id}/stats");

        $response->assertOk()
            ->assertJsonPath('data.id', $step->id)
            ->assertJsonPath('data.sequence_id', $sequence->id)
            ->assertJsonPath('data.stats.contacts', 1)
            ->assertJsonPath('data.mail_metrics.delivered', 1);
    }

    public function test_sequence_step_stats_returns_404_for_unknown_step(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->create();

        $this->getJson("/api/v1/sequences/{$sequence->id}/steps/999999/stats")->assertNotFound();
    }

    public function test_sequence_step_content_is_returned(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $response = $this->getJson("/api/v1/sequences/{$sequence->id}/steps/{$step->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $step->id)
            ->assertJsonPath('data.sequence_id', $sequence->id)
            ->assertJsonPath('data.filters', []);
    }

    public function test_sequence_step_content_returns_step_filters(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create([
            'filters' => [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']]],
        ]);

        $this->getJson("/api/v1/sequences/{$sequence->id}/steps/{$step->id}")
            ->assertOk()
            ->assertJsonPath('data.filters.0.category', 'tags')
            ->assertJsonPath('data.filters.0.values', ['vip']);
    }

    public function test_sequence_step_returns_404_for_unknown_step(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->create();

        $this->getJson("/api/v1/sequences/{$sequence->id}/steps/999999")->assertNotFound();
    }

    public function test_another_clients_sequence_is_not_accessible(): void
    {
        $other = Sequence::factory()->create();
        $otherStep = SequenceStep::factory()->for($other)->create();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/v1/sequences/{$other->id}")->assertNotFound();
        $this->getJson("/api/v1/sequences/{$other->id}/stats")->assertNotFound();
        $this->getJson("/api/v1/sequences/{$other->id}/steps/{$otherStep->id}")->assertNotFound();
        $this->getJson("/api/v1/sequences/{$other->id}/steps/{$otherStep->id}/stats")->assertNotFound();
    }

    public function test_sequences_require_authentication(): void
    {
        $this->getJson('/api/v1/sequences')->assertUnauthorized();
    }
}
