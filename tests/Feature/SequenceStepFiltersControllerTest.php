<?php

namespace Tests\Feature;

use App\Models\MailList;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenceStepFiltersControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_step_filters(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        [$sequence, $step] = $this->sequenceWithStep($user);

        $this->actingAs($user)
            ->patch($this->url($sequence, $step), ['filters' => [$this->listFilter($list)]])
            ->assertRedirect()
            ->assertSessionHas('success', 'Step filters saved.');

        $step->refresh();
        $this->assertCount(1, $step->filters);
        $this->assertSame('lists', $step->filters[0]['category']);
        $this->assertSame([$list->id], $step->filters[0]['values']);
    }

    public function test_owner_can_clear_step_filters(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        [$sequence, $step] = $this->sequenceWithStep($user, [$this->listFilter($list)]);

        $this->actingAs($user)
            ->patch($this->url($sequence, $step), ['filters' => []])
            ->assertRedirect();

        $this->assertSame([], $step->refresh()->filters);
    }

    public function test_invalid_filter_category_is_rejected(): void
    {
        $user = User::factory()->create();
        [$sequence, $step] = $this->sequenceWithStep($user);

        $this->actingAs($user)
            ->patch($this->url($sequence, $step), [
                'filters' => [['category' => 'nope', 'values' => [1]]],
            ])
            ->assertSessionHasErrors('filters.0.category');

        $this->assertNull($step->refresh()->filters);
    }

    public function test_list_of_another_client_is_rejected(): void
    {
        $user = User::factory()->create();
        $foreignList = MailList::factory()->for(User::factory())->create();
        [$sequence, $step] = $this->sequenceWithStep($user);

        $this->actingAs($user)
            ->patch($this->url($sequence, $step), ['filters' => [$this->listFilter($foreignList)]])
            ->assertSessionHasErrors();

        $this->assertNull($step->refresh()->filters);
    }

    public function test_tag_of_another_client_is_rejected(): void
    {
        $user = User::factory()->create();
        $foreignTag = Tag::factory()->for(User::factory())->create();
        [$sequence, $step] = $this->sequenceWithStep($user);

        $this->actingAs($user)
            ->patch($this->url($sequence, $step), [
                'filters' => [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => [$foreignTag->name]]],
            ])
            ->assertSessionHasErrors(['filters.0.values' => "The tag \"{$foreignTag->name}\" does not exist."]);

        $this->assertNull($step->refresh()->filters);
    }

    public function test_step_of_another_sequence_returns_not_found(): void
    {
        $user = User::factory()->create();
        [$sequence] = $this->sequenceWithStep($user);
        [, $otherStep] = $this->sequenceWithStep($user);

        $this->actingAs($user)
            ->patch($this->url($sequence, $otherStep), ['filters' => []])
            ->assertNotFound();
    }

    public function test_user_of_another_client_gets_not_found(): void
    {
        $owner = User::factory()->create();
        [$sequence, $step] = $this->sequenceWithStep($owner);

        $this->actingAs(User::factory()->create())
            ->patch($this->url($sequence, $step), ['filters' => []])
            ->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        [$sequence, $step] = $this->sequenceWithStep(User::factory()->create());

        $this->patch($this->url($sequence, $step), ['filters' => []])
            ->assertRedirect(route('login'));
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array{0: Sequence, 1: SequenceStep}
     */
    private function sequenceWithStep(User $user, ?array $filters = null): array
    {
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create(['filters' => $filters]);

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
        return route('sequences.steps.filters.update', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step->id,
        ]);
    }
}
