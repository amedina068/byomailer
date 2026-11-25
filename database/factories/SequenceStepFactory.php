<?php

namespace Database\Factories;

use App\Enums\SequenceStatus;
use App\Models\EmailIdentity;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories.Factory<\App\Models\SequenceStep>
 */
class SequenceStepFactory extends Factory
{
    /**
     * Configure the factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (SequenceStep $sequenceStep): void {
            $sequence = $sequenceStep->sequence()->withoutGlobalScopes()->firstOrFail();

            Tenant::run($sequence->client_id, fn () => $this->createMessage($sequenceStep, $sequence));
        });
    }

    private function createMessage(SequenceStep $sequenceStep, Sequence $sequence): void
    {
        $sequence->loadMissing('user');
        $identity = $sequence->user
            ? $sequence->user->emailIdentities()->first()
            : null;

        if (! $identity && $sequence->user) {
            $identity = EmailIdentity::factory()->for($sequence->user)->create();
        }

        $sequenceStep->message()->create([
            'user_id' => $sequence->user_id,
            'client_id' => $sequence->client_id,
            'email_identity_id' => $identity?->id,
            'subject' => fake()->sentence(),
            'from_email' => $identity?->email,
            'from_name' => $identity?->name,
            'html_content' => '<p>'.fake()->sentence().'</p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
            'text_content' => fake()->sentence(),
        ]);
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sequence_id' => Sequence::factory(),
            'delay_days' => fake()->numberBetween(0, 14),
            'delay_type' => 'days',
            'status' => fake()->randomElement(SequenceStatus::cases()),
        ];
    }
}
