<?php

namespace Tests\Feature;

use App\Models\MailList;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Characterization tests locking the segments index component + prop contract
 * (including the deferred `counts` prop) ahead of the Laravel 13 / Inertia v3 upgrade.
 */
class SegmentIndexCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_empty_state_shape(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('segments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('segments/Index')
                ->has('segments', 0)
                ->where('showingArchived', false)
                ->where('activeCount', 0)
                ->where('archivedCount', 0)
                ->missing('counts')
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->where('counts', [])
                )
            );
    }

    public function test_index_with_a_segment_shape_and_deferred_counts(): void
    {
        $user = User::factory()->create();
        $segment = Segment::factory()->create(['client_id' => $user->client_id, 'name' => 'VIPs']);

        $this->actingAs($user)
            ->get(route('segments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('segments/Index')
                ->where('showingArchived', false)
                ->where('activeCount', 1)
                ->where('archivedCount', 0)
                ->has('segments', 1, fn (Assert $segment) => $segment
                    ->where('name', 'VIPs')
                    ->has('id')
                    ->has('filters')
                    ->has('archived_at')
                    ->etc()
                )
                ->missing('counts')
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->where("counts.{$segment->id}", 0)
                )
            );
    }

    public function test_index_survives_segment_referencing_deleted_list(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id]);

        $segment = Segment::factory()
            ->withFilters([
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'operator' => 'in',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ])
            ->create(['client_id' => $user->client_id, 'name' => 'On the list']);

        $list->delete();

        $this->actingAs($user)
            ->get(route('segments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('segments/Index')
                ->where('activeCount', 1)
                ->has('segments', 1, fn (Assert $props) => $props
                    ->where('name', 'On the list')
                    ->has('id')
                    ->has('filters')
                    ->etc()
                )
                ->missing('counts')
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->where("counts.{$segment->id}", 0)
                )
            );
    }
}
