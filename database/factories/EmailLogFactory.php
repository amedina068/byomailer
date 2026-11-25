<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\EmailLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailLog>
 */
class EmailLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'provider' => 'ses',
            'operation' => fake()->randomElement(['SendBulkEmail', 'CreateEmailTemplate', 'DeleteEmailTemplate', 'SendEmail']),
            'recipient' => fake()->email(),
            'message_id' => fake()->uuid(),
            'status' => fake()->randomElement(['sent', 'success', 'failed', 'delivered', 'bounced', 'complained', 'opened']),
            'error' => null,
            'request_payload' => null,
            'response_payload' => null,
            'sns_payload' => null,
            'logged_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error' => fake()->sentence(),
        ]);
    }
}
