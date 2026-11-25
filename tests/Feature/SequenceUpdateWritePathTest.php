<?php

namespace Tests\Feature;

use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\UpdateSequenceTool;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The web dashboard, the API and the MCP server update a sequence through
 * the same Action, so they keep the same content variable keys and apply
 * the same filter and segment rule.
 */
class SequenceUpdateWritePathTest extends TestCase
{
    use RefreshDatabase;

    private const SENT = ['checkout_url' => 'https://example.com/checkout', 'coupon' => null];

    private const STORED = ['checkout_url' => 'https://example.com/checkout', 'coupon' => ''];

    public function test_web_keeps_a_variable_that_no_step_uses_yet(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('sequences.variables.update', $sequence), ['content_variables' => self::SENT])
            ->assertRedirect();

        $this->assertSame(self::STORED, $sequence->refresh()->content_variables);
    }

    public function test_api_keeps_a_variable_that_no_step_uses_yet(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $sequence = Sequence::factory()->for($user)->create();

        $this->patchJson("/api/v1/sequences/{$sequence->id}/variables", ['content_variables' => self::SENT])
            ->assertOk();

        $this->assertSame(self::STORED, $sequence->refresh()->content_variables);
    }

    public function test_mcp_keeps_a_variable_that_no_step_uses_yet(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'content_variables' => self::SENT,
        ])->assertOk();

        $this->assertSame(self::STORED, $sequence->refresh()->content_variables);
    }

    public function test_web_filters_clear_the_segment(): void
    {
        $user = User::factory()->create();
        Tag::factory()->for($user)->create(['name' => 'vip']);
        $segment = Segment::factory()->create(['client_id' => $user->client_id]);
        $sequence = Sequence::factory()->for($user)->create(['segment_id' => $segment->id, 'filters' => []]);
        $filters = [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']]];

        $this->actingAs($user)
            ->patch(route('sequences.filters.update', $sequence), ['filters' => $filters, 'segment_id' => null])
            ->assertSessionHasNoErrors();

        $sequence->refresh();
        $this->assertNull($sequence->segment_id);
        $this->assertSame($filters, $sequence->filters);
    }

    public function test_web_segment_clears_the_filters(): void
    {
        $user = User::factory()->create();
        $segment = Segment::factory()->create(['client_id' => $user->client_id]);
        $filters = [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']]];
        $sequence = Sequence::factory()->for($user)->create(['filters' => $filters]);

        $this->actingAs($user)
            ->patch(route('sequences.filters.update', $sequence), ['filters' => [], 'segment_id' => $segment->id])
            ->assertSessionHasNoErrors();

        $sequence->refresh();
        $this->assertSame($segment->id, $sequence->segment_id);
        $this->assertSame([], $sequence->filters);
    }

    public function test_mcp_filters_clear_the_segment(): void
    {
        $user = User::factory()->create();
        Tag::factory()->for($user)->create(['name' => 'vip']);
        $segment = Segment::factory()->create(['client_id' => $user->client_id]);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft', 'segment_id' => $segment->id, 'filters' => []]);
        $filters = [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']]];

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'filters' => $filters,
        ])->assertOk();

        $sequence->refresh();
        $this->assertNull($sequence->segment_id);
        $this->assertSame($filters, $sequence->filters);
    }

    public function test_mcp_rename_keeps_the_segment(): void
    {
        $user = User::factory()->create();
        $segment = Segment::factory()->create(['client_id' => $user->client_id]);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft', 'segment_id' => $segment->id, 'filters' => []]);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'name' => 'Renamed',
        ])->assertOk();

        $sequence->refresh();
        $this->assertSame('Renamed', $sequence->name);
        $this->assertSame($segment->id, $sequence->segment_id);
    }
}
