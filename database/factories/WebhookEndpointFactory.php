<?php

namespace Database\Factories;

use App\Enums\WebhookDisabledReason;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    use ResolvesClientFromUser;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => $this->clientOfUser(),
            'url' => 'https://'.fake()->domainName().'/webhooks/byomailer',
            'description' => fake()->optional()->sentence(3),
            'events' => ['contact.subscribed'],
            'active' => true,
            'consecutive_failures' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
            'disabled_reason' => WebhookDisabledReason::Manual,
            'disabled_at' => now(),
        ]);
    }

    public function autoDisabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
            'disabled_reason' => WebhookDisabledReason::AutoFailures,
            'disabled_at' => now(),
            'consecutive_failures' => 15,
        ]);
    }
}
