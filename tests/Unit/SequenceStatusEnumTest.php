<?php

namespace Tests\Unit;

use App\Enums\SequenceStatus;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ValueError;

class SequenceStatusEnumTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stored_values_do_not_change(): void
    {
        $this->assertSame(['draft', 'live'], array_column(SequenceStatus::cases(), 'value'));
    }

    public function test_paused_is_an_input_alias_of_draft_and_not_a_stored_status(): void
    {
        $this->assertNull(SequenceStatus::tryFrom('paused'));
        $this->assertSame(SequenceStatus::Draft, SequenceStatus::fromInput('paused'));
        $this->assertSame(SequenceStatus::Draft, SequenceStatus::fromInput('draft'));
        $this->assertSame(SequenceStatus::Live, SequenceStatus::fromInput('live'));
        $this->assertSame(['draft', 'live', 'paused'], SequenceStatus::inputValues());
    }

    public function test_an_unknown_input_value_is_rejected(): void
    {
        $this->expectException(ValueError::class);

        SequenceStatus::fromInput('archived');
    }

    public function test_each_status_has_a_label_for_the_pages(): void
    {
        $this->assertSame(
            [['value' => 'draft', 'label' => 'Draft'], ['value' => 'live', 'label' => 'Live']],
            SequenceStatus::options(),
        );
    }

    public function test_the_sequence_casts_the_status_and_stores_the_string_value(): void
    {
        $sequence = Sequence::factory()->create(['status' => SequenceStatus::Live]);

        $this->assertDatabaseHas('sequences', ['id' => $sequence->id, 'status' => 'live']);

        $sequence->update(['status' => SequenceStatus::Draft]);

        $this->assertSame(SequenceStatus::Draft, $sequence->fresh()->status);
        $this->assertDatabaseHas('sequences', ['id' => $sequence->id, 'status' => 'draft']);
    }

    public function test_the_step_casts_the_status_and_stores_the_string_value(): void
    {
        $step = SequenceStep::factory()->create(['status' => SequenceStatus::Draft]);

        $this->assertDatabaseHas('sequence_steps', ['id' => $step->id, 'status' => 'draft']);

        $step->update(['status' => SequenceStatus::Live]);

        $this->assertSame(SequenceStatus::Live, $step->fresh()->status);
        $this->assertDatabaseHas('sequence_steps', ['id' => $step->id, 'status' => 'live']);
    }
}
