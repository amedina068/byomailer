<?php

namespace Tests\Feature;

use App\Enums\DelayUnit;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Services\SequenceStepScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenceStepDelayUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_step_casts_the_delay_unit_to_the_enum(): void
    {
        $step = SequenceStep::factory()->create(['delay_type' => 'hours']);

        $this->assertSame(DelayUnit::Hours, $step->refresh()->delay_type);
        $this->assertDatabaseHas('sequence_steps', ['id' => $step->id, 'delay_type' => 'hours']);
    }

    public function test_a_new_step_without_a_unit_uses_days_before_a_refresh(): void
    {
        $step = SequenceStep::create([
            'sequence_id' => Sequence::factory()->create()->id,
            'delay_days' => 2,
            'status' => 'draft',
        ]);

        $this->assertSame(DelayUnit::Days, $step->delay_type);
        $this->assertDatabaseHas('sequence_steps', ['id' => $step->id, 'delay_type' => 'days']);
    }

    public function test_the_delay_in_hours_sql_gives_the_same_hours_as_the_enum(): void
    {
        foreach (DelayUnit::cases() as $unit) {
            $step = SequenceStep::factory()->create(['delay_days' => 3, 'delay_type' => $unit]);

            $hours = SequenceStep::query()
                ->whereKey($step->id)
                ->value(SequenceStepScheduler::delayInHoursExpression());

            $this->assertSame($unit->toHours(3), (int) $hours, "Wrong hours for the {$unit->value} unit.");
        }
    }

    public function test_each_unit_converts_a_delay_to_hours(): void
    {
        $this->assertSame(72, DelayUnit::Days->toHours(3));
        $this->assertSame(3, DelayUnit::Hours->toHours(3));
    }

    public function test_the_max_delay_is_one_year_in_each_unit(): void
    {
        $this->assertSame(365, DelayUnit::maxDelayFor('days'));
        $this->assertSame(8760, DelayUnit::maxDelayFor('hours'));
        $this->assertSame(365, DelayUnit::maxDelayFor('weeks'));
        $this->assertSame(365, DelayUnit::maxDelayFor(['hours']));
        $this->assertSame(365, DelayUnit::maxDelayFor(null));
    }
}
