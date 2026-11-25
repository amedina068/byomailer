<?php

namespace Tests\Feature\Mcp;

use App\Events\ContactSubscribed;
use App\Jobs\SendOptInEmailJob;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\AddContactToListTool;
use App\Mcp\Tools\AddTagsToContactTool;
use App\Mcp\Tools\CreateContactTool;
use App\Mcp\Tools\FindContactTool;
use App\Mcp\Tools\RemoveContactFromListTool;
use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ContactToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_contact_reuses_an_existing_email_and_saves_a_blacklisted_one_as_unsubscribed(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $existing = Contact::factory()->for($user)->create([
            'email' => 'ada@example.com',
            'name' => 'Ada',
            'extra_attributes' => ['plan' => 'pro'],
        ]);
        BlacklistedEmail::factory()->for($user)->create([
            'email' => 'blocked@example.com',
        ]);

        $reused = ByoMailerServer::actingAs($user)->tool(CreateContactTool::class, [
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
        ]);

        $reused->assertOk()
            ->assertSee('"created":false')
            ->assertSee('Ada Lovelace');

        $existing->refresh();
        $this->assertSame('Ada Lovelace', $existing->name);
        $this->assertSame(['plan' => 'pro'], $existing->extra_attributes);
        $this->assertSame(1, Contact::query()->where('email', 'ada@example.com')->count());

        $created = ByoMailerServer::actingAs($user)->tool(CreateContactTool::class, [
            'email' => 'fresh@example.com',
            'tags' => ['VIP'],
            'list_id' => $list->id,
        ]);

        $created->assertOk()->assertSee('"created":true')->assertSee('VIP');
        $fresh = Contact::query()->where('email', 'fresh@example.com')->first();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->lists->contains($list));
        $this->assertTrue($fresh->tags->contains('name', 'VIP'));

        $blocked = ByoMailerServer::actingAs($user)->tool(CreateContactTool::class, [
            'email' => 'blocked@example.com',
        ]);

        $blocked->assertOk()->assertSee('"created":true');
        $this->assertNotNull(Contact::query()->where('email', 'blocked@example.com')->sole()->unsubscribed_at);
    }

    public function test_add_contact_to_list_opts_in_or_queues_the_opt_in_mail(): void
    {
        Event::fake([ContactSubscribed::class]);
        Bus::fake([SendOptInEmailJob::class]);

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create(['opt_in_enabled' => false]);
        $optInList = MailList::factory()->for($user)->create(['opt_in_enabled' => true]);

        $added = ByoMailerServer::actingAs($user)->tool(AddContactToListTool::class, [
            'contact_id' => $contact->id,
            'list_id' => $list->id,
        ]);

        $added->assertOk();
        $membership = $contact->lists()->where('lists.id', $list->id)->first();
        $this->assertNotNull($membership?->pivot->opted_in_at);
        Event::assertDispatchedTimes(ContactSubscribed::class, 1);
        Bus::assertNothingDispatched();

        ByoMailerServer::actingAs($user)->tool(AddContactToListTool::class, [
            'contact_id' => $contact->id,
            'list_id' => $list->id,
        ]);

        Event::assertDispatchedTimes(ContactSubscribed::class, 1);

        $queued = ByoMailerServer::actingAs($user)->tool(AddContactToListTool::class, [
            'contact_id' => $contact->id,
            'list_id' => $optInList->id,
        ]);

        $queued->assertOk();
        Bus::assertDispatched(SendOptInEmailJob::class);
        $optInMembership = $contact->lists()->where('lists.id', $optInList->id)->first();
        $this->assertNull($optInMembership?->pivot->opted_in_at);
    }

    public function test_add_tags_to_contact_creates_a_missing_tag_without_detaching(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $existing = Tag::factory()->for($user)->create(['name' => 'Customer']);
        $contact->tags()->attach($existing->id);

        $response = ByoMailerServer::actingAs($user)->tool(AddTagsToContactTool::class, [
            'contact_id' => $contact->id,
            'tags' => ['Customer', 'VIP'],
        ]);

        $response->assertOk()->assertSee('VIP');

        $names = $contact->tags()->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Customer', 'VIP'], $names);
        $this->assertNotNull(Tag::query()->where('name', 'VIP')->where('user_id', $user->id)->first());
    }

    public function test_remove_contact_from_list_detaches_only_that_list(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $keep = MailList::factory()->for($user)->create();
        $drop = MailList::factory()->for($user)->create();
        $contact->lists()->attach([$keep->id, $drop->id]);

        $response = ByoMailerServer::actingAs($user)->tool(RemoveContactFromListTool::class, [
            'contact_id' => $contact->id,
            'list_id' => $drop->id,
        ]);

        $response->assertOk();
        $listIds = $contact->lists()->pluck('lists.id')->all();
        $this->assertSame([$keep->id], $listIds);
    }

    public function test_create_contact_never_changes_the_subscription(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'paused@example.com',
            'unsubscribed_at' => now()->subDay(),
            'unsubscribed_by_contact' => false,
        ]);

        ByoMailerServer::actingAs($user)->tool(CreateContactTool::class, [
            'email' => 'paused@example.com',
            'name' => 'Synced Name',
        ])->assertOk();

        $this->assertNotNull($contact->fresh()->unsubscribed_at);

        ByoMailerServer::actingAs($user)->tool(CreateContactTool::class, [
            'email' => 'paused@example.com',
            'list_id' => $list->id,
        ])->assertOk();

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
        $this->assertTrue($contact->lists()->whereKey($list->id)->exists());
    }

    public function test_find_contact_is_scoped_and_returns_unsubscribed_contacts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Contact::factory()->for($user)->create([
            'email' => 'gone@example.com',
            'unsubscribed_at' => now(),
        ]);
        Contact::factory()->for($other)->create([
            'email' => 'secret@example.com',
        ]);

        $found = ByoMailerServer::actingAs($user)->tool(FindContactTool::class, [
            'email' => 'gone@example.com',
        ]);

        $found->assertOk()
            ->assertSee('gone@example.com')
            ->assertSee('unsubscribed_at');

        $hidden = ByoMailerServer::actingAs($user)->tool(FindContactTool::class, [
            'email' => 'secret@example.com',
        ]);

        $hidden->assertOk()->assertDontSee('secret@example.com');
    }

    public function test_find_contact_returns_the_sequences_of_the_contact(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Onboarding']);
        Sequence::factory()->for($user)->create(['name' => 'Winback']);
        $contact = Contact::factory()->for($user)->create(['email' => 'ada@example.com']);
        $contact->sequences()->attach($sequence->id);

        $found = ByoMailerServer::actingAs($user)->tool(FindContactTool::class, [
            'email' => 'ada@example.com',
        ]);

        $found->assertOk()
            ->assertSee('"sequences":[{"id":'.$sequence->id.',"name":"Onboarding"')
            ->assertDontSee('Winback');
    }

    public function test_tools_reject_another_clients_ids_without_writing(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherContact = Contact::factory()->for($other)->create();
        $otherList = MailList::factory()->for($other)->create();

        $create = ByoMailerServer::actingAs($user)->tool(CreateContactTool::class, [
            'email' => 'new@example.com',
            'list_id' => $otherList->id,
        ]);

        $create->assertHasErrors();
        $this->assertNull(Contact::query()->where('email', 'new@example.com')->first());

        $ownList = MailList::factory()->for($user)->create();

        $addList = ByoMailerServer::actingAs($user)->tool(AddContactToListTool::class, [
            'contact_id' => $otherContact->id,
            'list_id' => $ownList->id,
        ]);

        $addList->assertHasErrors();
        $this->assertSame(0, $otherContact->lists()->count());

        $addTags = ByoMailerServer::actingAs($user)->tool(AddTagsToContactTool::class, [
            'contact_id' => $otherContact->id,
            'tags' => ['Nope'],
        ]);

        $addTags->assertHasErrors();
        $this->assertNull(Tag::query()->where('name', 'Nope')->first());

        $remove = ByoMailerServer::actingAs($user)->tool(RemoveContactFromListTool::class, [
            'contact_id' => $otherContact->id,
            'list_id' => $otherList->id,
        ]);

        $remove->assertHasErrors();
    }
}
