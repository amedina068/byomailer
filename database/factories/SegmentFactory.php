<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Segment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->unique()->words(2, true),
            'description' => null,
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'date',
                    'operator' => 'in_last',
                    'values' => ['days' => 30],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $filters
     */
    public function withFilters(array $filters): static
    {
        return $this->state(fn (): array => ['filters' => $filters]);
    }
}
