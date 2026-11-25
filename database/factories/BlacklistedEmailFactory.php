<?php

namespace Database\Factories;

use App\Models\BlacklistedEmail;
use App\Models\User;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlacklistedEmail>
 */
class BlacklistedEmailFactory extends Factory
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
            'email' => $this->faker->unique()->safeEmail(),
            'reason' => 'bounce',
        ];
    }

    public function global(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => null,
        ]);
    }
}
