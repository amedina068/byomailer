<?php

namespace Tests\Feature;

use App\Models\ContactMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContactMailReputationIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_mails_has_client_sent_at_index_and_no_lone_client_id_index(): void
    {
        $indexes = collect(Schema::getIndexes('contact_mails'))->keyBy('name');

        $this->assertSame(['client_id', 'sent_at', 'bounced_at', 'complained_at'], $indexes['contact_mails_client_sent_at_index']['columns']);
        $this->assertSame(['client_id', 'mailable_type', 'mailable_id'], $indexes['contact_mails_client_mailable_index']['columns']);
        $this->assertFalse($indexes->has('contact_mails_client_id_index'));
    }

    public function test_window_count_matches_client_count_after_index_change(): void
    {
        $mail = ContactMail::factory()->create(['sent_at' => now()->subDay()]);
        ContactMail::factory()->create();

        $clientCount = ContactMail::withoutGlobalScopes()->where('client_id', $mail->client_id)->count();
        $windowCount = ContactMail::withoutGlobalScopes()
            ->where('client_id', $mail->client_id)
            ->where('sent_at', '>=', now()->subDays(30))
            ->count();

        $this->assertSame(1, $clientCount);
        $this->assertSame($clientCount, $windowCount);
    }
}
