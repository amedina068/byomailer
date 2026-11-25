<?php

namespace Tests\Feature;

use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\ContactFilterService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suppression used to be enforced only when a contact was created through the
 * API. Since suppression comes from bounce and complaint feedback, it almost
 * always lands after the contact exists, so known-bad addresses kept being
 * mailed — which is what pushes the bounce rate past the SES thresholds.
 */
class BlacklistEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_blacklisted_contact_is_excluded_from_newsletter_recipients(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $good = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);
        $bad = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);

        BlacklistedEmail::factory()->for($user)->create(['email' => $bad->email]);

        $recipients = app(ContactFilterService::class)
            ->newsletterRecipientsQuery($user->client_id, [])
            ->pluck('id');

        $this->assertTrue($recipients->contains($good->id));
        $this->assertFalse($recipients->contains($bad->id));
    }

    public function test_a_globally_blacklisted_address_is_excluded_for_every_client(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);

        BlacklistedEmail::factory()->global()->create(['email' => $contact->email]);

        $count = app(ContactFilterService::class)
            ->recipientCount($user->client_id, []);

        $this->assertSame(0, $count);
    }

    public function test_another_clients_blacklist_does_not_affect_this_client(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $other = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);

        BlacklistedEmail::factory()->for($other)->create(['email' => $contact->email]);

        $count = app(ContactFilterService::class)
            ->recipientCount($user->client_id, []);

        $this->assertSame(1, $count);
    }

    public function test_the_sequence_sender_fails_a_due_mail_to_a_blacklisted_address(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);

        Tenant::run($user->client_id, fn () => $contact->syncSequences([$sequence->id], true));

        $mail = ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $step->id)
            ->firstOrFail();

        BlacklistedEmail::factory()->for($user)->create(['email' => $contact->email]);

        $this->withoutSesSendingLimit();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $mail->refresh();

        $this->assertSame('failed', $mail->status);
        $this->assertSame('blacklisted', $mail->failure_reason);
        $this->assertNull($mail->sent_at);
    }
}
