<?php

namespace Tests\Feature;

use App\Events\ContactListsSynced;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\AddContactToListTool;
use App\Mcp\Tools\BulkUpdateContactsTool;
use App\Mcp\Tools\CreateContactTool;
use App\Mcp\Tools\RemoveContactFromListTool;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\MailList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Each channel adds and removes list members with the same rules: a removal
 * fires `ContactListsSynced`, and an add never changes the subscription. An
 * unsubscribe by an admin and an unsubscribe by the contact both stay.
 */
class ContactListMembershipTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->list = MailList::factory()->for($this->user)->create();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function removeChannels(): array
    {
        return [
            'api' => ['api'],
            'mcp remove tool' => ['mcp'],
            'mcp bulk tool' => ['mcp-bulk'],
        ];
    }

    #[DataProvider('removeChannels')]
    public function test_a_removal_fires_contact_lists_synced(string $channel): void
    {
        Event::fake([ContactListsSynced::class]);
        Automation::factory()->for($this->user)->create([
            'condition' => ['type' => 'removed_from_list', 'value' => $this->list->id],
        ]);
        $contact = Contact::factory()->for($this->user)->create();
        $contact->lists()->attach($this->list->id);

        $this->removeFromList($channel, $contact);

        $this->assertFalse($contact->lists()->whereKey($this->list->id)->exists());
        Event::assertDispatched(ContactListsSynced::class, fn (ContactListsSynced $event): bool => $event->contact->is($contact)
            && $event->changes['detached'] === [$this->list->id]);
    }

    public function test_a_removal_from_a_list_that_the_contact_is_not_on_fires_nothing(): void
    {
        Event::fake([ContactListsSynced::class]);
        Automation::factory()->for($this->user)->create([
            'condition' => ['type' => 'removed_from_list', 'value' => $this->list->id],
        ]);
        $contact = Contact::factory()->for($this->user)->create();

        $this->removeFromList('api', $contact);

        Event::assertNotDispatched(ContactListsSynced::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function addChannels(): array
    {
        return [
            'api upsert' => ['api'],
            'mcp create tool' => ['mcp-create'],
            'mcp add tool' => ['mcp-add'],
            'mcp bulk tool' => ['mcp-bulk'],
            'web sync' => ['web'],
        ];
    }

    #[DataProvider('addChannels')]
    public function test_an_add_keeps_an_unsubscribe_that_an_admin_made(string $channel): void
    {
        $contact = Contact::factory()->for($this->user)->create(['unsubscribed_at' => now()->subDay(), 'unsubscribed_by_contact' => false]);

        $this->addToList($channel, $contact);

        $this->assertTrue($contact->lists()->whereKey($this->list->id)->exists());
        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }

    #[DataProvider('addChannels')]
    public function test_an_add_keeps_an_unsubscribe_that_the_contact_made(string $channel): void
    {
        $contact = Contact::factory()->for($this->user)->create(['unsubscribed_at' => now()->subDay(), 'unsubscribed_by_contact' => true]);

        $this->addToList($channel, $contact);

        $this->assertTrue($contact->lists()->whereKey($this->list->id)->exists());
        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }

    private function removeFromList(string $channel, Contact $contact): void
    {
        match ($channel) {
            'api' => $this->apiUser()->deleteJson("/api/v1/lists/{$this->list->id}/contacts/{$contact->id}")->assertOk(),
            'mcp' => ByoMailerServer::actingAs($this->user)->tool(RemoveContactFromListTool::class, [
                'contact_id' => $contact->id,
                'list_id' => $this->list->id,
            ])->assertOk(),
            'mcp-bulk' => ByoMailerServer::actingAs($this->user)->tool(BulkUpdateContactsTool::class, [
                'contacts' => [['contact_id' => $contact->id, 'remove_from_lists' => [$this->list->id]]],
            ])->assertOk(),
        };
    }

    private function addToList(string $channel, Contact $contact): void
    {
        match ($channel) {
            'api' => $this->apiUser()->postJson("/api/v1/lists/{$this->list->id}/contacts", ['email' => $contact->email])->assertOk(),
            'mcp-create' => ByoMailerServer::actingAs($this->user)->tool(CreateContactTool::class, [
                'email' => $contact->email,
                'list_id' => $this->list->id,
            ])->assertOk(),
            'mcp-add' => ByoMailerServer::actingAs($this->user)->tool(AddContactToListTool::class, [
                'contact_id' => $contact->id,
                'list_id' => $this->list->id,
            ])->assertOk(),
            'mcp-bulk' => ByoMailerServer::actingAs($this->user)->tool(BulkUpdateContactsTool::class, [
                'contacts' => [['contact_id' => $contact->id, 'add_to_lists' => [$this->list->id]]],
            ])->assertOk(),
            'web' => $this->actingAs($this->user)->post(route('contacts.lists.sync', $contact), ['list_ids' => [$this->list->id]])->assertRedirect(),
        };
    }

    private function apiUser(): static
    {
        Sanctum::actingAs($this->user, ['*']);

        return $this;
    }
}
