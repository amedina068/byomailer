<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\User;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
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
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'extra_attributes' => [],
            'unsubscribed_at' => null,
            'unsubscribed_by_contact' => false,
        ];
    }

    /**
     * Place the contact in a country (2-letter code) — for location filters.
     */
    public function inCountry(string $country = 'US'): static
    {
        return $this->state(fn (): array => ['country' => $country]);
    }

    /**
     * Place the contact in a timezone — for location filters.
     */
    public function inTimezone(string $timezone = 'America/New_York'): static
    {
        return $this->state(fn (): array => ['timezone' => $timezone]);
    }

    /**
     * Set the signup date relative to now — for date filters.
     */
    public function createdDaysAgo(int $days): static
    {
        return $this->state(fn (): array => ['created_at' => Carbon::now()->subDays($days)]);
    }

    /**
     * Set the signup date to a fixed calendar date (Y-m-d) — for date filters.
     */
    public function createdOn(string $date): static
    {
        return $this->state(fn (): array => ['created_at' => Carbon::parse($date)]);
    }

    /**
     * Merge custom attributes into extra_attributes — for attribute filters.
     *
     * @param  array<string, scalar>  $attributes
     */
    public function withAttributes(array $attributes): static
    {
        return $this->state(fn (array $current): array => [
            'extra_attributes' => array_merge($current['extra_attributes'] ?? [], $attributes),
        ]);
    }

    public function unsubscribed(): static
    {
        return $this->state(fn (): array => [
            'unsubscribed_at' => Carbon::now(),
            'unsubscribed_by_contact' => true,
        ]);
    }
}
