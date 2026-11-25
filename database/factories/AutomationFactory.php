<?php

namespace Database\Factories;

use App\Models\Automation;
use App\Models\User;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
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
            'type' => 'simple',
            'condition' => [
                'type' => 'contact_added_to_list',
                'value' => 1,
            ],
            'actions' => [
                ['type' => 'add_tag', 'value' => 1],
            ],
            'is_live' => true,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_live' => false,
        ]);
    }
}
