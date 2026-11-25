<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VisualAutomation;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisualAutomation>
 */
class VisualAutomationFactory extends Factory
{
    use ResolvesClientFromUser;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => $this->clientOfUser(),
            'name' => fake()->sentence(3),
            'status' => 'draft',
            'workflow' => [
                'nodes' => [
                    [
                        'id' => 'trigger-1',
                        'type' => 'trigger',
                        'position' => ['x' => 0, 'y' => 0],
                        'data' => [
                            'trigger_type' => 'contact_added_to_list',
                            'value' => 1,
                        ],
                    ],
                ],
                'edges' => [],
            ],
        ];
    }

    public function live(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'live',
        ]);
    }
}
