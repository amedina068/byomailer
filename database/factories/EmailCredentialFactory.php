<?php

namespace Database\Factories;

use App\Models\EmailCredential;
use App\Models\User;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailCredential>
 */
class EmailCredentialFactory extends Factory
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
            'type' => 'ses',
            'access_key' => fake()->regexify('[A-Z0-9]{20}'),
            'secret_key' => fake()->regexify('[A-Za-z0-9/+=]{40}'),
            'region' => fake()->randomElement(['us-east-1', 'us-west-2', 'eu-central-1']),
            'tenant_name' => null,
            'aws_account_id' => fake()->numerify('############'),
            'configuration_set_name' => null,
            'configuration_set_created_at' => null,
            'sns_topic_arn' => null,
            'sns_subscription_arn' => null,
            'sns_subscription_confirmed_at' => null,
            'open_tracking_enabled' => true,
            'click_tracking_enabled' => true,
            'can_create_template' => true,
            'can_delete_template' => true,
            'can_send_bulk_email' => true,
            'can_send_email' => true,
            'permissions_checked_at' => now(),
            'permissions_alert_count' => 0,
            'permissions_alerted_at' => null,
            'production_access_enabled' => false,
            'sending_enabled' => true,
            'max_24_hour_send' => 200,
            'max_send_rate' => 1,
            'sent_last_24_hours' => 0,
            'quota_fetched_at' => null,
        ];
    }

    /**
     * Indicate that the credential has a configuration set.
     */
    public function withConfigurationSet(): static
    {
        return $this->state(fn (array $attributes) => [
            'configuration_set_name' => 'mailautomation-'.fake()->randomNumber(5).'-'.fake()->regexify('[a-z0-9]{8}'),
            'configuration_set_created_at' => now(),
        ]);
    }

    /**
     * Indicate that the credential has a tenant configured.
     */
    public function withTenant(string $name = 'byomailer'): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_name' => $name,
        ]);
    }

    /**
     * Indicate that the credential has production access.
     */
    public function production(): static
    {
        return $this->state(fn (array $attributes) => [
            'production_access_enabled' => true,
            'max_24_hour_send' => 50000,
            'max_send_rate' => 14,
            'quota_fetched_at' => now(),
        ]);
    }
}
