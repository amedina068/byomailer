<?php

namespace Tests\Feature\Api;

use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SequenceVariablesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_detected_variables_and_saved_defaults(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->withContentVariables(['first_name' => 'Friend'])->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $step->message->update([
            'subject' => 'Hi {{ first_name }}',
            'html_content' => '<a href="{{ checkout_url }}">Buy</a> {{ unsubscribe_url }}',
        ]);

        $this->getJson("/api/v1/sequences/{$sequence->id}/variables")
            ->assertOk()
            ->assertJsonPath('data.sequence_id', $sequence->id)
            ->assertJsonPath('data.detected_variables', ['checkout_url', 'first_name'])
            ->assertJsonPath('data.content_variables.first_name', 'Friend')
            ->assertJsonPath('data.content_variables.checkout_url', '');
    }

    public function test_update_replaces_the_defaults(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->withContentVariables(['old' => 'value'])->create();

        $this->patchJson("/api/v1/sequences/{$sequence->id}/variables", [
            'content_variables' => ['checkout_url' => 'https://example.com/checkout', 'first_name' => null],
        ])->assertOk()
            ->assertJsonPath('data.content_variables.checkout_url', 'https://example.com/checkout')
            ->assertJsonPath('data.content_variables.first_name', '');

        $this->assertSame(
            ['checkout_url' => 'https://example.com/checkout', 'first_name' => ''],
            $sequence->refresh()->content_variables,
        );
    }

    public function test_update_rejects_a_default_longer_than_the_dashboard_allows(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->withContentVariables(['checkout_url' => 'old'])->create();

        $this->patchJson("/api/v1/sequences/{$sequence->id}/variables", [
            'content_variables' => ['checkout_url' => str_repeat('a', 256)],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['content_variables.checkout_url' => 'must not be greater than 255 characters']);

        $this->assertSame(['checkout_url' => 'old'], $sequence->refresh()->content_variables);
    }

    public function test_update_requires_the_content_variables_field(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->withContentVariables(['checkout_url' => 'old'])->create();

        $this->patchJson("/api/v1/sequences/{$sequence->id}/variables", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content_variables' => 'must be present']);

        $this->assertSame(['checkout_url' => 'old'], $sequence->refresh()->content_variables);
    }

    public function test_another_clients_sequence_returns_not_found(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $foreign = Sequence::factory()->withContentVariables(['checkout_url' => 'old'])->create();

        $this->getJson("/api/v1/sequences/{$foreign->id}/variables")->assertNotFound();
        $this->patchJson("/api/v1/sequences/{$foreign->id}/variables", ['content_variables' => []])->assertNotFound();

        $this->assertSame(['checkout_url' => 'old'], $foreign->refresh()->content_variables);
    }
}
