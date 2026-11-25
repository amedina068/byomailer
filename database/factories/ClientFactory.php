<?php

namespace Database\Factories;

use App\Enums\ClientRole;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'email' => fake()->unique()->safeEmail(),
            'timezone' => fake()->timezone(),
            'postal_address' => fake()->address(),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => [
            'role' => ClientRole::Admin,
        ]);
    }
}
