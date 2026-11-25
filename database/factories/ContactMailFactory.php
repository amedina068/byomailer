<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<ContactMail>
 */
class ContactMailFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'client_id' => fn (array $a) => DB::table('contacts')->where('id', $a['contact_id'])->value('client_id'),
            'mailable_type' => Newsletter::class,
            'mailable_id' => $this->newsletterOfContactAuthor(),
            'message_id' => fn (array $a) => DB::table('messages')
                ->where('messageable_type', $a['mailable_type'])
                ->where('messageable_id', $a['mailable_id'])
                ->orderBy('id')
                ->value('id'),
            'subject' => fake()->sentence(),
            'from_email' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'template_data' => [
                'contact' => [
                    'name' => fake()->name(),
                    'email' => fake()->safeEmail(),
                ],
                'unsubscribe_url' => fake()->url(),
            ],
            'scheduled_at' => now(),
            'sent_at' => now(),
            'bounced_at' => null,
            'delivered_at' => null,
            'opened_at' => null,
            'clicked_at' => null,
            'rejected_at' => null,
            'complained_at' => null,
            'unsubscribed_at' => null,
            'status' => 'sent',
        ];
    }

    public function forNewsletter(?Newsletter $newsletter = null): static
    {
        return $this->state(fn (array $attributes) => [
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter?->id ?? $this->newsletterOfContactAuthor(),
        ]);
    }

    public function forSequenceStep(?SequenceStep $sequenceStep = null): static
    {
        return $this->state(fn (array $attributes) => [
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $sequenceStep?->id ?? $this->sequenceStepOfContactAuthor(),
        ]);
    }

    /**
     * A new newsletter by the author of the contact, so that the mail stays in one client.
     */
    private function newsletterOfContactAuthor(): Closure
    {
        return fn (array $a) => Newsletter::factory()->state([
            'user_id' => DB::table('contacts')->where('id', $a['contact_id'])->value('user_id'),
        ]);
    }

    /**
     * A new sequence step by the author of the contact, so that the mail stays in one client.
     */
    private function sequenceStepOfContactAuthor(): Closure
    {
        return fn (array $a) => SequenceStep::factory()->for(Sequence::factory()->state([
            'user_id' => DB::table('contacts')->where('id', $a['contact_id'])->value('user_id'),
        ]));
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'sent_at' => null,
            'delivered_at' => null,
            'bounced_at' => null,
            'complained_at' => null,
            'unsubscribed_at' => null,
        ]);
    }

    /**
     * A sequence step mail the contact skipped because of the step filters.
     */
    public function skipped(): static
    {
        return $this->pending()->state(fn (array $attributes) => [
            'status' => 'skipped',
        ]);
    }

    /**
     * Mark the mail as sent/received. Matches the engagement filter's
     * `received` operator (sent_at is set).
     */
    public function sent(?Carbon $at = null): static
    {
        $at ??= Carbon::now();

        return $this->state(fn (array $attributes) => [
            'status' => 'sent',
            'sent_at' => $at,
            'delivered_at' => null,
            'bounced_at' => null,
            'complained_at' => null,
            'unsubscribed_at' => null,
            'opened_at' => null,
            'clicked_at' => null,
        ]);
    }

    /**
     * Mark the mail as delivered. Matches the engagement filter's `received`
     * operator (delivered_at is set). Pass $at to control the delivery time.
     */
    public function delivered(?Carbon $at = null): static
    {
        $at ??= Carbon::now()->subMinutes(9);

        return $this->state(fn (array $attributes) => [
            'status' => 'delivered',
            'sent_at' => $at->copy()->subMinute(),
            'delivered_at' => $at,
            'opened_at' => null,
            'clicked_at' => null,
            'bounced_at' => null,
            'complained_at' => null,
            'unsubscribed_at' => null,
        ]);
    }

    /**
     * Mark the mail as opened. Matches the engagement filter's `opened`
     * operator. Pass $at to control the open time for last-N-days windows.
     */
    public function opened(?Carbon $at = null): static
    {
        $at ??= Carbon::now()->subMinutes(8);

        return $this->state(fn (array $attributes) => [
            'status' => 'opened',
            'sent_at' => $at->copy()->subMinutes(2),
            'delivered_at' => $at->copy()->subMinute(),
            'opened_at' => $at,
            'clicked_at' => null,
            'bounced_at' => null,
            'complained_at' => null,
            'unsubscribed_at' => null,
        ]);
    }

    public function bounced(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'bounced',
            'sent_at' => now()->subMinutes(5),
            'bounced_at' => now(),
            'delivered_at' => null,
            'complained_at' => null,
            'unsubscribed_at' => null,
        ]);
    }

    public function unsubscribed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'unsubscribed',
            'sent_at' => now()->subMinutes(5),
            'delivered_at' => now()->subMinutes(4),
            'unsubscribed_at' => now(),
            'bounced_at' => null,
            'complained_at' => null,
        ]);
    }

    public function complained(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'complained',
            'sent_at' => now()->subMinutes(5),
            'delivered_at' => now()->subMinutes(4),
            'complained_at' => now(),
            'bounced_at' => null,
            'unsubscribed_at' => null,
            'clicked_at' => null,
        ]);
    }

    /**
     * Mark the mail as clicked (implies opened). Matches the engagement
     * filter's `clicked` operator. Pass $at to control the click time.
     */
    public function clicked(?Carbon $at = null): static
    {
        $at ??= Carbon::now()->subMinutes(7);

        return $this->state(fn (array $attributes) => [
            'status' => 'opened',
            'sent_at' => $at->copy()->subMinutes(3),
            'delivered_at' => $at->copy()->subMinutes(2),
            'opened_at' => $at->copy()->subMinute(),
            'clicked_at' => $at,
            'bounced_at' => null,
            'complained_at' => null,
            'unsubscribed_at' => null,
        ]);
    }
}
