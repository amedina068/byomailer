<?php

namespace Tests\Feature\Mcp;

use App\Events\ContactAttributesChanged;
use App\Events\ContactTagsSynced;
use App\Jobs\SendOptInEmailJob;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\BulkUpdateContactsTool;
use App\Mcp\Tools\ListContactsTool;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\MailList;
use App\Models\Tag;
use App\Models\User;
use App\Services\ContactBulkUpdater;
use App\Services\ContactMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class BulkContactToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_contacts_is_scoped_and_hides_unsubscribed_contacts_by_default(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'VIP']);
        $list = MailList::factory()->for($user)->create();
        $active = Contact::factory()->for($user)->create([
            'email' => 'ada@example.com',
            'extra_attributes' => ['plan' => 'pro'],
        ]);
        $active->tags()->attach($tag->id);
        $active->lists()->attach($list->id);
        Contact::factory()->for($user)->create([
            'email' => 'gone@example.com',
            'unsubscribed_at' => now(),
        ]);
        Contact::factory()->for($other)->create(['email' => 'secret@example.com']);

        $response = ByoMailerServer::actingAs($user)->tool(ListContactsTool::class, []);

        $response->assertOk()
            ->assertSee('ada@example.com')
            ->assertSee('"tags":["VIP"]')
            ->assertSee('"lists":['.$list->id.']')
            ->assertSee('"attributes":{"plan":"pro"}')
            ->assertSee('"total":1')
            ->assertDontSee('gone@example.com')
            ->assertDontSee('secret@example.com');

        $withUnsubscribed = ByoMailerServer::actingAs($user)->tool(ListContactsTool::class, [
            'include_unsubscribed' => true,
        ]);

        $withUnsubscribed->assertOk()
            ->assertSee('gone@example.com')
            ->assertSee('"total":2')
            ->assertDontSee('secret@example.com');
    }

    public function test_list_contacts_applies_filters_and_search(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'Customer']);
        $tagged = Contact::factory()->for($user)->create(['email' => 'tagged@example.com', 'name' => 'Grace']);
        $tagged->tags()->attach($tag->id);
        $taggedOther = Contact::factory()->for($user)->create(['email' => 'other@example.com', 'name' => 'Linus']);
        $taggedOther->tags()->attach($tag->id);
        Contact::factory()->for($user)->create(['email' => 'plain@example.com']);

        $filtered = ByoMailerServer::actingAs($user)->tool(ListContactsTool::class, [
            'filters' => [
                ['category' => 'tags', 'mode' => 'any', 'values' => ['Customer']],
            ],
        ]);

        $filtered->assertOk()
            ->assertSee('tagged@example.com')
            ->assertSee('other@example.com')
            ->assertDontSee('plain@example.com')
            ->assertSee('"total":2');

        $searched = ByoMailerServer::actingAs($user)->tool(ListContactsTool::class, [
            'filters' => [
                ['category' => 'tags', 'mode' => 'any', 'values' => ['Customer']],
            ],
            'search' => 'grace',
        ]);

        $searched->assertOk()
            ->assertSee('tagged@example.com')
            ->assertDontSee('other@example.com')
            ->assertSee('"total":1');
    }

    public function test_list_contacts_pages_with_after_id(): void
    {
        $user = User::factory()->create();
        $contacts = Contact::factory()->count(3)->for($user)->create();

        $first = ByoMailerServer::actingAs($user)->tool(ListContactsTool::class, ['per_page' => 2]);

        $first->assertOk()
            ->assertSee($contacts[0]->email)
            ->assertSee($contacts[1]->email)
            ->assertDontSee($contacts[2]->email)
            ->assertSee('"has_more":true')
            ->assertSee('"next_after_id":'.$contacts[1]->id)
            ->assertSee('"total":3');

        $second = ByoMailerServer::actingAs($user)->tool(ListContactsTool::class, [
            'per_page' => 2,
            'after_id' => $contacts[1]->id,
        ]);

        $second->assertOk()
            ->assertSee($contacts[2]->email)
            ->assertDontSee($contacts[0]->email)
            ->assertSee('"has_more":false')
            ->assertSee('"next_after_id":null');

        ByoMailerServer::actingAs($user)->tool(ListContactsTool::class, ['per_page' => 101])
            ->assertHasErrors(['per page field must not be greater than 100']);
    }

    public function test_bulk_update_changes_tags_attributes_and_name_by_id_and_by_email(): void
    {
        $user = User::factory()->create();
        $old = Tag::factory()->for($user)->create(['name' => 'Lead']);
        $keep = Tag::factory()->for($user)->create(['name' => 'Newsletter']);
        $first = Contact::factory()->for($user)->create([
            'email' => 'ada@example.com',
            'extra_attributes' => ['plan' => 'free', 'city' => 'London', 'legacy' => 'x'],
        ]);
        $first->tags()->attach([$old->id, $keep->id]);
        $second = Contact::factory()->for($user)->create([
            'email' => 'grace@example.com',
            'extra_attributes' => ['plan' => 'free'],
        ]);

        $response = ByoMailerServer::actingAs($user)->tool(BulkUpdateContactsTool::class, [
            'contacts' => [
                [
                    'contact_id' => $first->id,
                    'name' => 'Ada Lovelace',
                    'add_tags' => ['Customer'],
                    'remove_tags' => ['Lead', 'Unknown'],
                    'set_attributes' => ['plan' => 'pro', 'seats' => 5],
                    'remove_attributes' => ['legacy'],
                ],
                [
                    'email' => 'grace@example.com',
                    'add_tags' => ['Customer'],
                    'set_attributes' => ['plan' => 'pro', 'trial' => true],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertSee('"updated":2')
            ->assertSee('"failed":0');

        $first->refresh();
        $this->assertSame('Ada Lovelace', $first->name);
        $this->assertSame(['plan' => 'pro', 'city' => 'London', 'seats' => 5], $first->extra_attributes);
        $this->assertEqualsCanonicalizing(['Newsletter', 'Customer'], $first->tags()->pluck('name')->all());

        $second->refresh();
        $this->assertSame(['plan' => 'pro', 'trial' => true], $second->extra_attributes);
        $this->assertSame(['Customer'], $second->tags()->pluck('name')->all());

        $this->assertSame(1, Tag::query()->where('name', 'Customer')->count());
        $this->assertNull(Tag::query()->where('name', 'Unknown')->first());
    }

    public function test_bulk_update_adds_and_removes_lists(): void
    {
        Bus::fake([SendOptInEmailJob::class]);

        $user = User::factory()->create();
        $open = MailList::factory()->for($user)->create(['opt_in_enabled' => false]);
        $optIn = MailList::factory()->for($user)->create(['opt_in_enabled' => true]);
        $drop = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($drop->id);

        $response = ByoMailerServer::actingAs($user)->tool(BulkUpdateContactsTool::class, [
            'contacts' => [
                [
                    'contact_id' => $contact->id,
                    'add_to_lists' => [$open->id, $optIn->id],
                    'remove_from_lists' => [$drop->id],
                ],
            ],
        ]);

        $response->assertOk()->assertSee('"updated":1');

        $this->assertEqualsCanonicalizing([$open->id, $optIn->id], $contact->lists()->pluck('lists.id')->all());
        $this->assertNotNull($contact->lists()->where('lists.id', $open->id)->first()?->pivot->opted_in_at);
        $this->assertNull($contact->lists()->where('lists.id', $optIn->id)->first()?->pivot->opted_in_at);
        Bus::assertDispatchedTimes(SendOptInEmailJob::class, 1);
    }

    public function test_bulk_update_reports_failed_items_and_still_updates_the_others(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Contact::factory()->for($other)->create(['extra_attributes' => []]);
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => []]);

        $response = ByoMailerServer::actingAs($user)->tool(BulkUpdateContactsTool::class, [
            'contacts' => [
                ['contact_id' => $foreign->id, 'set_attributes' => ['plan' => 'pro']],
                ['email' => 'missing@example.com', 'add_tags' => ['VIP']],
                ['contact_id' => $contact->id],
                ['contact_id' => $contact->id, 'add_tags' => ['VIP'], 'remove_tags' => ['VIP']],
                ['contact_id' => $contact->id, 'set_attributes' => ['plan' => 'pro']],
            ],
        ]);

        $response->assertOk()
            ->assertSee('"updated":1')
            ->assertSee('"failed":4')
            ->assertSee("No contact found with id {$foreign->id}.")
            ->assertSee('No contact found with email missing@example.com.')
            ->assertSee('No changes given.')
            ->assertSee('The same value is in add_tags and remove_tags: VIP.');

        $this->assertSame([], $foreign->refresh()->extra_attributes);
        $this->assertSame(['plan' => 'pro'], $contact->refresh()->extra_attributes);
        $this->assertNull(Tag::query()->where('name', 'VIP')->first());
    }

    public function test_bulk_update_isolates_an_item_that_throws(): void
    {
        $user = User::factory()->create();
        $broken = Contact::factory()->for($user)->create(['extra_attributes' => []]);
        $healthy = Contact::factory()->for($user)->create(['extra_attributes' => []]);

        $this->app->instance(ContactBulkUpdater::class, new class($broken->id, app(ContactMembership::class)) extends ContactBulkUpdater
        {
            public function __construct(private readonly int $brokenId, ContactMembership $membership)
            {
                parent::__construct($membership);
            }

            public function apply(User $user, Contact $contact, array $changes, Collection $addToLists, Collection $removeFromLists): Contact
            {
                if ($contact->id === $this->brokenId) {
                    throw new RuntimeException('Simulated failure.');
                }

                return parent::apply($user, $contact, $changes, $addToLists, $removeFromLists);
            }
        });

        $response = ByoMailerServer::actingAs($user)->tool(BulkUpdateContactsTool::class, [
            'contacts' => [
                ['contact_id' => $broken->id, 'set_attributes' => ['plan' => 'pro']],
                ['contact_id' => $healthy->id, 'set_attributes' => ['plan' => 'pro']],
            ],
        ]);

        $response->assertOk()
            ->assertSee('"updated":1')
            ->assertSee('"failed":1')
            ->assertSee('The update failed. The other items are not affected.')
            ->assertDontSee('Simulated failure.');

        $this->assertSame(['plan' => 'pro'], $healthy->refresh()->extra_attributes);
    }

    public function test_bulk_update_rejects_a_list_of_another_account_without_writing(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreignList = MailList::factory()->for($other)->create();
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => []]);

        $response = ByoMailerServer::actingAs($user)->tool(BulkUpdateContactsTool::class, [
            'contacts' => [
                ['contact_id' => $contact->id, 'set_attributes' => ['plan' => 'pro']],
                ['contact_id' => $contact->id, 'add_to_lists' => [$foreignList->id]],
            ],
        ]);

        $response->assertHasErrors(["No list found with id {$foreignList->id}. Nothing was changed."]);
        $this->assertSame([], $contact->refresh()->extra_attributes);
        $this->assertSame(0, $contact->lists()->count());
    }

    public function test_bulk_update_validates_attribute_names_and_values(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => []]);

        ByoMailerServer::actingAs($user)->tool(BulkUpdateContactsTool::class, [
            'contacts' => [['contact_id' => $contact->id, 'set_attributes' => ['Email' => 'x@example.com']]],
        ])->assertHasErrors(['The attribute name "Email" is reserved. Use the name field instead.']);

        ByoMailerServer::actingAs($user)->tool(BulkUpdateContactsTool::class, [
            'contacts' => [['contact_id' => $contact->id, 'set_attributes' => ['plan' => ['nested' => true]]]],
        ])->assertHasErrors(['The value of attribute "plan" must be a string of maximum 255 characters, a number or a boolean.']);

        ByoMailerServer::actingAs($user)->tool(BulkUpdateContactsTool::class, [
            'contacts' => [['contact_id' => $contact->id, 'set_attributes' => ['pro', 'free']]],
        ])->assertHasErrors(['set_attributes must be an object of attribute names and values.']);

        ByoMailerServer::actingAs($user)->tool(BulkUpdateContactsTool::class, [
            'contacts' => array_fill(0, 101, ['contact_id' => $contact->id, 'add_tags' => ['VIP']]),
        ])->assertHasErrors(['contacts field must not have more than 100 items']);

        $this->assertSame([], $contact->refresh()->extra_attributes);
    }

    public function test_bulk_update_runs_tag_and_attribute_automations(): void
    {
        Event::fake([ContactTagsSynced::class, ContactAttributesChanged::class]);

        $user = User::factory()->create();
        $lead = Tag::factory()->for($user)->create(['name' => 'Lead']);
        Automation::factory()->for($user)->create([
            'condition' => ['type' => 'removed_tag', 'value' => $lead->id],
            'actions' => [['type' => 'add_tag', 'value' => $lead->id]],
        ]);
        Automation::factory()->for($user)->create([
            'condition' => ['type' => 'attribute_changed', 'attribute' => 'plan', 'operator' => 'changed'],
            'actions' => [['type' => 'add_tag', 'value' => $lead->id]],
        ]);
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'free']]);
        $contact->tags()->attach($lead->id);

        ByoMailerServer::actingAs($user)->tool(BulkUpdateContactsTool::class, [
            'contacts' => [
                ['contact_id' => $contact->id, 'remove_tags' => ['Lead'], 'add_tags' => ['Customer'], 'set_attributes' => ['plan' => 'pro']],
            ],
        ])->assertOk();

        $customer = Tag::query()->where('name', 'Customer')->firstOrFail();

        Event::assertDispatchedTimes(ContactTagsSynced::class, 1);
        Event::assertDispatched(ContactTagsSynced::class, fn (ContactTagsSynced $event): bool => $event->contact->is($contact)
            && array_values($event->changes['detached']) === [$lead->id]
            && array_values($event->changes['attached']) === [$customer->id]);
        Event::assertDispatched(ContactAttributesChanged::class, fn (ContactAttributesChanged $event): bool => $event->changes === [
            'plan' => ['old' => 'free', 'new' => 'pro'],
        ]);
    }
}
