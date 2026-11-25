<?php

namespace Database\Factories;

use App\Enums\SequenceStatus;
use App\Models\User;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories.Factory<\App\Models\Sequence>
 */
class SequenceFactory extends Factory
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
            'status' => fake()->randomElement(SequenceStatus::cases()),
            'filters' => [],
            'content_variables' => null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $filters
     */
    public function withFilters(array $filters): static
    {
        return $this->state(fn (array $attributes): array => [
            'filters' => $filters,
        ]);
    }

    /**
     * @param  array<string, string|null>  $variables
     */
    public function withContentVariables(array $variables): static
    {
        return $this->state(fn (array $attributes): array => [
            'content_variables' => $variables,
        ]);
    }
}
