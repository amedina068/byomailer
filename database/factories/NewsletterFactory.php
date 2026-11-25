<?php

namespace Database\Factories;

use App\Enums\AbVariant;
use App\Enums\NewsletterStatus;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;
use App\Support\Tenant;
use Database\Factories\Concerns\ResolvesClientFromUser;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Newsletter>
 */
class NewsletterFactory extends Factory
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
            'title' => fake()->sentence(3),
            'scheduled_at' => null,
            'sent_at' => null,
            'status' => NewsletterStatus::Draft,
            'filters' => [],
            'content_variables' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Newsletter $newsletter) => Tenant::run($newsletter->client_id, function () use ($newsletter): void {
            if ($newsletter->message()->exists()) {
                return;
            }

            $identity = EmailIdentity::factory()->for($newsletter->user)->create();

            $variant = $newsletter->ab_test_enabled ? AbVariant::A : null;

            $newsletter->message()->create([
                'user_id' => $newsletter->user_id,
                'client_id' => $newsletter->client_id,
                'email_identity_id' => $identity->id,
                'subject' => fake()->sentence(),
                'from_email' => $identity->email,
                'from_name' => $identity->name ?: $newsletter->user->name,
                'markdown_content' => '# '.fake()->sentence(),
                'html_content' => '<p>'.fake()->sentence().'</p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
                'text_content' => fake()->sentence(),
                'variant' => $variant,
            ]);
        }));
    }

    public function scheduled(?DateTimeInterface $scheduledAt = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => NewsletterStatus::Scheduled,
            'scheduled_at' => $scheduledAt ?? now()->addDay(),
        ]);
    }

    public function withAbTest(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ab_test_enabled' => true,
        ])->afterCreating(fn (Newsletter $newsletter) => Tenant::run($newsletter->client_id, function () use ($newsletter): void {
            if ($newsletter->messageB()->exists()) {
                return;
            }

            $messageA = $newsletter->messageA;

            if ($messageA && $messageA->variant !== AbVariant::A) {
                $messageA->forceFill(['variant' => AbVariant::A])->save();
            }

            $newsletter->messages()->create([
                'user_id' => $newsletter->user_id,
                'client_id' => $newsletter->client_id,
                'email_identity_id' => $messageA?->email_identity_id,
                'subject' => fake()->sentence().' [B]',
                'from_email' => $messageA?->from_email,
                'from_name' => $messageA?->from_name,
                'markdown_content' => '',
                'html_content' => '<p>'.fake()->sentence().' Variant B.</p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
                'text_content' => fake()->sentence().' Variant B.',
                'variant' => AbVariant::B,
            ]);
        }));
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

    public function withAttributeFilters(): static
    {
        return $this->state(fn (array $attributes): array => [
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'attributes',
                    'mode' => 'all',
                    'values' => [
                        ['key' => 'status', 'value' => 'active'],
                        ['key' => 'plan', 'value' => 'pro'],
                    ],
                ],
            ],
        ]);
    }

    public function withTagFilters(): static
    {
        return $this->state(fn (array $attributes): array => [
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'tags',
                    'mode' => 'any',
                    'values' => ['vip', 'premium'],
                ],
            ],
        ]);
    }

    public function withMixedFilters(): static
    {
        return $this->state(fn (array $attributes): array => [
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'attributes',
                    'mode' => 'all',
                    'values' => [
                        ['key' => 'status', 'value' => 'active'],
                    ],
                ],
                [
                    'connector' => 'and',
                    'category' => 'tags',
                    'mode' => 'any',
                    'values' => ['vip', 'premium'],
                ],
            ],
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

    /**
     * @param  array<int>  $listIds
     */
    public function withListFilters(array $listIds): static
    {
        return $this->state(fn (array $attributes): array => [
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => $listIds,
                ],
            ],
        ]);
    }
}
