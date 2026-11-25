<?php

namespace Database\Factories;

use App\Models\ContactAttribute;
use App\Models\User;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactAttribute>
 */
class ContactAttributeFactory extends Factory
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
            'name' => fake()->unique()->word(),
        ];
    }

    public function withDefault(?string $value = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'default_value' => $value ?? fake()->word(),
        ]);
    }
}
