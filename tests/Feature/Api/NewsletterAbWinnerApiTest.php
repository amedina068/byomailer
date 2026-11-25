<?php

namespace Tests\Feature\Api;

use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NewsletterAbWinnerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_pick_ab_winner_when_status_is_ab_testing(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $newsletter = Newsletter::factory()->for($user)->withAbTest()->create([
            'status' => 'ab_testing',
        ]);

        $response = $this->postJson("/api/v1/newsletters/{$newsletter->id}/ab-winner", [
            'winner_variant' => 'b',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.ab_test_winner_variant', 'b')
            ->assertJsonPath('data.status', 'sent');

        $this->assertDatabaseHas('newsletters', [
            'id' => $newsletter->id,
            'status' => 'sent',
            'ab_test_winner_variant' => 'b',
        ]);
    }

    public function test_ab_winner_rejects_invalid_variant(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->withAbTest()->create(['status' => 'ab_testing']);

        $response = $this->postJson("/api/v1/newsletters/{$newsletter->id}/ab-winner", [
            'winner_variant' => 'c',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('winner_variant');
    }

    public function test_ab_winner_requires_ab_testing_status(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);

        $response = $this->postJson("/api/v1/newsletters/{$newsletter->id}/ab-winner", [
            'winner_variant' => 'a',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_ab_winner_rejects_when_message_b_missing(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => 'ab_testing',
            'ab_test_enabled' => true,
        ]);
        // factory doesn't create messageB unless withAbTest() — simulate the bug case
        $newsletter->messageB()->delete();

        $response = $this->postJson("/api/v1/newsletters/{$newsletter->id}/ab-winner", [
            'winner_variant' => 'a',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('ab_test_enabled');
    }

    public function test_ab_winner_does_not_leak_across_clients(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $other = User::factory()->create();
        $newsletter = Newsletter::factory()->for($other)->withAbTest()->create(['status' => 'ab_testing']);

        $response = $this->postJson("/api/v1/newsletters/{$newsletter->id}/ab-winner", [
            'winner_variant' => 'a',
        ]);

        $response->assertNotFound();
    }
}
