<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\User;
use App\Services\EmailSendingGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailSendingGateTest extends TestCase
{
    use RefreshDatabase;

    private function gate(): EmailSendingGate
    {
        return app(EmailSendingGate::class);
    }

    public function test_every_client_can_send_without_a_plan(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        $decision = $this->gate()->evaluate($user, 1_000_000);

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->reason);
    }

    public function test_a_large_send_history_does_not_block(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $contact = Contact::factory()->for($user)->create();
        ContactMail::factory()->for($contact)->forNewsletter()->count(30)->create();

        $this->assertTrue($this->gate()->evaluate($user, 1_000_000)->allowed);
    }

    public function test_a_paused_ses_account_blocks(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create([
            'type' => 'ses',
            'quota_fetched_at' => now(),
            'sending_enabled' => false,
        ]);

        $decision = $this->gate()->evaluate($user->fresh());

        $this->assertFalse($decision->allowed);
        $this->assertSame(EmailSendingGate::SES_SENDING_DISABLED_REASON, $decision->reason);
        $this->assertSame('ses_account', $decision->errorKey());
    }
}
