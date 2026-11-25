<?php

namespace Tests\Feature;

use App\Jobs\ProcessContactImportBatchJob;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CreateContactTool;
use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\MailList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A new contact with a blacklisted email is saved as unsubscribed in the
 * dashboard, MCP and the import. The API refuses it with 422.
 */
class BlacklistedContactSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        BlacklistedEmail::factory()->for($this->user)->create(['email' => 'blocked@example.com']);
    }

    public function test_web_create_saves_a_blacklisted_email_as_unsubscribed(): void
    {
        $this->actingAs($this->user)->post(route('contacts.store'), ['email' => 'blocked@example.com'])
            ->assertRedirect();

        $this->assertBlockedContactIsUnsubscribed();
    }

    public function test_web_update_to_a_blacklisted_email_unsubscribes_the_contact(): void
    {
        $contact = Contact::factory()->for($this->user)->create(['email' => 'ada@example.com']);

        $this->actingAs($this->user)->patch(route('contacts.update', $contact), ['email' => 'blocked@example.com', 'name' => 'Ada'])
            ->assertRedirect();

        $this->assertBlockedContactIsUnsubscribed();
    }

    public function test_api_list_upsert_refuses_a_new_blacklisted_email(): void
    {
        $list = MailList::factory()->for($this->user)->create();
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson("/api/v1/lists/{$list->id}/contacts", ['email' => 'blocked@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'This email address has been blacklisted.']);

        $this->assertFalse(Contact::withoutGlobalScopes()->where('email', 'blocked@example.com')->exists());
    }

    public function test_mcp_create_saves_a_blacklisted_email_as_unsubscribed(): void
    {
        ByoMailerServer::actingAs($this->user)->tool(CreateContactTool::class, ['email' => 'blocked@example.com'])
            ->assertOk()
            ->assertSee('"created":true');

        $this->assertBlockedContactIsUnsubscribed();
    }

    public function test_import_saves_a_blacklisted_email_as_unsubscribed(): void
    {
        $list = MailList::factory()->for($this->user)->create();
        $contactImport = ContactImport::factory()->processing()->for($this->user)->for($list, 'mailList')
            ->create(['total_count' => 1, 'processed_count' => 0, 'skipped_count' => 0]);
        $rows = [['email' => 'blocked@example.com', 'name' => null, 'tags' => null, 'attributes' => []]];

        [$job] = (new ProcessContactImportBatchJob($this->user, $list, $contactImport, $rows))->withFakeBatch();
        app()->call([$job, 'handle']);

        $this->assertBlockedContactIsUnsubscribed();
    }

    private function assertBlockedContactIsUnsubscribed(): void
    {
        $contact = Contact::withoutGlobalScopes()->where('email', 'blocked@example.com')->sole();

        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertFalse($contact->unsubscribed_by_contact);
    }
}
