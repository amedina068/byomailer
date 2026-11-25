<?php

namespace Tests\Feature\Api;

use App\Models\MailList;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SequenceStepFiltersApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_step_filters_can_be_saved(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $list = MailList::factory()->for($user)->create();
        [$sequence, $step] = $this->sequenceWithStep($user);

        $this->patchJson($this->url($sequence, $step), ['filters' => [$this->listFilter($list)]])
            ->assertOk()
            ->assertJsonPath('data.id', $step->id)
            ->assertJsonPath('data.filters.0.category', 'lists')
            ->assertJsonPath('data.filters.0.values', [$list->id]);

        $this->assertSame([$list->id], $step->refresh()->filters[0]['values']);
    }

    public function test_step_filters_can_be_saved_on_a_live_step(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $tag = Tag::factory()->for($user)->create(['name' => 'vip']);
        [$sequence, $step] = $this->sequenceWithStep($user, null, 'live');

        $this->patchJson($this->url($sequence, $step), [
            'filters' => [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => [$tag->name]]],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'live')
            ->assertJsonPath('data.filters.0.values', ['vip']);
    }

    public function test_step_filters_can_be_cleared(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $list = MailList::factory()->for($user)->create();
        [$sequence, $step] = $this->sequenceWithStep($user, [$this->listFilter($list)]);

        $this->patchJson($this->url($sequence, $step), ['filters' => []])
            ->assertOk()
            ->assertJsonPath('data.filters', []);

        $this->assertSame([], $step->refresh()->filters);
    }

    public function test_filters_field_is_required(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        [$sequence, $step] = $this->sequenceWithStep($user);

        $this->patchJson($this->url($sequence, $step), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filters' => 'The filters field must be present.']);
    }

    public function test_invalid_filter_category_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        [$sequence, $step] = $this->sequenceWithStep($user);

        $this->patchJson($this->url($sequence, $step), [
            'filters' => [['category' => 'nope', 'values' => [1]]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filters.0.category' => 'The selected filters.0.category is invalid.']);

        $this->assertNull($step->refresh()->filters);
    }

    public function test_tag_of_another_client_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $foreignTag = Tag::factory()->for(User::factory())->create();
        [$sequence, $step] = $this->sequenceWithStep($user);

        $this->patchJson($this->url($sequence, $step), [
            'filters' => [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => [$foreignTag->name]]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filters.0.values' => "The tag \"{$foreignTag->name}\" does not exist."]);

        $this->assertNull($step->refresh()->filters);
    }

    public function test_step_of_another_sequence_returns_not_found(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        [$sequence] = $this->sequenceWithStep($user);
        [, $otherStep] = $this->sequenceWithStep($user);

        $this->patchJson($this->url($sequence, $otherStep), ['filters' => []])
            ->assertNotFound();

        $this->assertNull($otherStep->refresh()->filters);
    }

    public function test_step_of_another_client_returns_not_found(): void
    {
        [$sequence, $step] = $this->sequenceWithStep(User::factory()->create());
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->patchJson($this->url($sequence, $step), ['filters' => []])
            ->assertNotFound();

        $this->assertNull($step->refresh()->filters);
    }

    public function test_guest_is_unauthorized(): void
    {
        [$sequence, $step] = $this->sequenceWithStep(User::factory()->create());

        $this->patchJson($this->url($sequence, $step), ['filters' => []])
            ->assertUnauthorized();
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array{0: Sequence, 1: SequenceStep}
     */
    private function sequenceWithStep(User $user, ?array $filters = null, string $status = 'draft'): array
    {
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create(['filters' => $filters, 'status' => $status]);

        return [$sequence, $step];
    }

    /**
     * @return array<string, mixed>
     */
    private function listFilter(MailList $list): array
    {
        return [
            'connector' => 'and',
            'category' => 'lists',
            'mode' => 'any',
            'values' => [$list->id],
        ];
    }

    private function url(Sequence $sequence, SequenceStep $step): string
    {
        return "/api/v1/sequences/{$sequence->id}/steps/{$step->id}/filters";
    }
}
