<?php

namespace Tests\Feature;

use App\Jobs\ProcessContactImportBatchJob;
use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\ContactAttribute;
use App\Models\ContactImport;
use App\Models\ContactMail;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contacts, lists, tags, topics and attributes belong to the client. Each
 * member of the client sees and changes the records that another member added.
 */
class TeamAudienceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $teammate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->teammate = User::factory()->create(['client_id' => $this->owner->client_id]);
    }

    public function test_a_teammate_sees_the_contacts_of_the_client(): void
    {
        $contact = Contact::factory()->for($this->owner)->create();
        Contact::factory()->create();

        $this->actingAs($this->teammate)->get(route('contacts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('contacts.data', 1)
                ->where('contacts.data.0.id', $contact->id));
    }

    public function test_a_teammate_exports_the_contacts_and_the_attributes_of_the_client(): void
    {
        Contact::factory()->for($this->owner)->create(['email' => 'shared@example.com', 'extra_attributes' => ['plan' => 'pro']]);

        $csv = $this->actingAs($this->teammate)->get(route('contacts.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString('shared@example.com', $csv);
        $this->assertStringContainsString(',plan', strtok($csv, "\n"));
    }

    public function test_a_teammate_cannot_add_an_email_that_the_client_has(): void
    {
        Contact::factory()->for($this->owner)->create(['email' => 'shared@example.com']);

        $this->actingAs($this->teammate)
            ->post(route('contacts.store'), ['email' => 'shared@example.com'])
            ->assertSessionHasErrors('email');
    }

    public function test_a_teammate_updates_a_contact_of_the_client_through_the_api(): void
    {
        $contact = Contact::factory()->for($this->owner)->create(['email' => 'shared@example.com']);
        Contact::factory()->for($this->owner)->create(['email' => 'taken@example.com']);
        Sanctum::actingAs($this->teammate, ['*']);

        $this->putJson("/api/v1/contacts/{$contact->id}", ['email' => 'shared@example.com', 'name' => 'Shared'])
            ->assertOk();

        $this->putJson("/api/v1/contacts/{$contact->id}", ['email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'The email has already been taken.']);
    }

    public function test_an_update_by_a_teammate_keeps_a_suppressed_contact_unsubscribed(): void
    {
        $contact = Contact::factory()->for($this->owner)->create([
            'email' => 'bounced@example.com',
            'unsubscribed_at' => now()->subDay(),
            'unsubscribed_by_contact' => false,
        ]);
        BlacklistedEmail::withoutGlobalScopes()->create([
            'user_id' => $this->owner->id,
            'client_id' => $this->owner->client_id,
            'email' => 'bounced@example.com',
            'reason' => 'bounce',
        ]);
        Sanctum::actingAs($this->teammate, ['*']);

        $this->putJson("/api/v1/contacts/{$contact->id}", ['name' => 'Still bounced'])->assertOk();

        $this->assertNotNull($contact->refresh()->unsubscribed_at);
    }

    public function test_an_import_by_a_teammate_updates_the_contacts_and_tags_of_the_client(): void
    {
        $list = MailList::factory()->for($this->owner)->create();
        $contact = Contact::factory()->for($this->owner)->create(['email' => 'shared@example.com', 'name' => 'Old']);
        $tag = Tag::factory()->for($this->owner)->create(['name' => 'VIP']);
        $contactImport = ContactImport::factory()->processing()->for($this->teammate)->for($list, 'mailList')->create([
            'total_count' => 1,
            'processed_count' => 0,
            'skipped_count' => 0,
        ]);

        [$job] = (new ProcessContactImportBatchJob($this->teammate, $list, $contactImport, [[
            'email' => 'shared@example.com',
            'name' => 'New',
            'tags' => 'VIP',
            'attributes' => [],
        ]]))->withFakeBatch();
        app()->call([$job, 'handle']);

        $this->assertSame(1, Contact::withoutGlobalScopes()->count());
        $this->assertSame('New', $contact->refresh()->name);
        $this->assertSame($this->owner->id, $contact->user_id);
        Tenant::run($this->owner->client_id, function () use ($contact, $tag, $list): void {
            $this->assertTrue($contact->tags->contains($tag));
            $this->assertTrue($contact->lists->contains($list));
        });
    }

    public function test_a_teammate_imports_into_a_list_of_the_client(): void
    {
        $list = MailList::factory()->for($this->owner)->create();

        $this->actingAs($this->teammate)->get(route('contacts.import'))
            ->assertInertia(fn (Assert $page) => $page->has('lists', 1));

        $this->actingAs($this->teammate)
            ->post(route('lists.import'), ['list_id' => $list->id])
            ->assertSessionDoesntHaveErrors('list_id');
    }

    public function test_a_teammate_follows_the_progress_of_an_import_of_the_client(): void
    {
        $list = MailList::factory()->for($this->owner)->create();
        $contactImport = ContactImport::factory()->processing()->for($this->owner)->for($list, 'mailList')->create([
            'total_count' => 4,
            'processed_count' => 2,
            'skipped_count' => 0,
        ]);

        $this->actingAs($this->teammate)->getJson(route('contact-imports.status', $contactImport))
            ->assertOk()
            ->assertJson(['id' => $contactImport->id, 'progress' => 50]);
    }

    public function test_a_teammate_opens_a_mail_that_the_client_sent(): void
    {
        $contact = Contact::factory()->for($this->owner)->create();
        $newsletter = Newsletter::factory()->for($this->owner)->create();
        $mail = ContactMail::factory()->for($contact)->for($newsletter, 'mailable')->create(['subject' => 'Team news']);

        $this->actingAs($this->teammate)->get(route('contact-mails.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('contact-mails/Show')
                ->where('mail.subject', 'Team news'));
    }

    public function test_a_teammate_manages_the_lists_and_topics_of_the_client(): void
    {
        $list = MailList::factory()->for($this->owner)->create(['name' => 'Shared list']);
        $topic = Topic::factory()->for($this->owner)->create(['list_id' => $list->id]);

        $this->actingAs($this->teammate);

        $this->get(route('lists.index'))->assertInertia(fn (Assert $page) => $page->has('lists', 1));
        $this->get(route('lists.show', $list->id))->assertOk();
        $this->put(route('lists.update', $list->id), ['name' => 'Renamed'])->assertRedirect();
        $this->put(route('lists.topics.update', [$list->id, $topic->id]), ['name' => 'Topic'])->assertRedirect();

        $this->assertSame('Renamed', $list->refresh()->name);
        $this->assertSame('Topic', $topic->refresh()->name);

        Sanctum::actingAs($this->teammate, ['*']);
        $this->getJson("/api/v1/lists/{$list->id}/topics")->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson("/api/v1/lists/{$list->id}/topics/{$topic->id}", ['name' => 'API topic'])->assertOk();
    }

    public function test_a_teammate_manages_the_tags_of_the_client(): void
    {
        $tag = Tag::factory()->for($this->owner)->create(['name' => 'VIP']);

        $this->actingAs($this->teammate)->get(route('tags.index'))
            ->assertInertia(fn (Assert $page) => $page->has('tags', 1));

        Sanctum::actingAs($this->teammate, ['*']);

        $this->getJson('/api/v1/tags')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/tags', ['name' => 'VIP'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'The name has already been taken.']);
        $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'Gold'])->assertOk();
        $this->deleteJson("/api/v1/tags/{$tag->id}")->assertNoContent();
    }

    public function test_the_audience_filters_offer_the_lists_tags_and_attributes_of_the_client(): void
    {
        MailList::factory()->for($this->owner)->create();
        Tag::factory()->for($this->owner)->create();
        ContactAttribute::factory()->for($this->owner)->create(['name' => 'plan']);
        $sequence = Sequence::factory()->for($this->teammate)->create();

        $this->actingAs($this->teammate)->get(route('sequences.show', $sequence->id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('lists', 1)
                ->has('tags', 1)
                ->where('contactAttributes', ['plan']));
    }
}
