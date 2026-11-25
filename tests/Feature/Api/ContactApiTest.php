<?php

namespace Tests\Feature\Api;

use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\MailList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_can_be_created_with_tags_and_extra_attributes_in_a_list(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/lists/{$list->id}/contacts", [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'status' => 'completed',
            'popular' => true,
            'tags' => ['popular', 'client'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'john@example.com')
            ->assertJsonPath('data.name', 'John Doe')
            ->assertJsonPath('data.extra_attributes.status', 'completed')
            ->assertJsonCount(2, 'data.tags')
            ->assertJsonCount(1, 'data.lists');

        $this->assertDatabaseHas('contacts', [
            'user_id' => $user->id,
            'email' => 'john@example.com',
            'extra_attributes->status' => 'completed',
        ]);

        $contact = Contact::where('email', 'john@example.com')->first();
        $this->assertTrue($contact->lists->contains($list));

        foreach (['popular', 'client'] as $tag) {
            $this->assertDatabaseHas('tags', [
                'user_id' => $user->id,
                'name' => $tag,
            ]);
        }
    }

    public function test_contact_is_added_to_list_when_created(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/lists/{$list->id}/contacts", [
            'email' => 'newcontact@example.com',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('contact_list', [
            'list_id' => $list->id,
        ]);

        $contact = Contact::where('email', 'newcontact@example.com')->first();
        $this->assertNotNull($contact);
        $this->assertTrue($contact->lists->contains($list));
    }

    public function test_existing_contact_is_added_to_new_list_on_upsert(): void
    {
        $user = User::factory()->create();
        $list1 = MailList::factory()->for($user)->create();
        $list2 = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'existing@example.com',
        ]);
        $contact->lists()->attach($list1->id);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/lists/{$list2->id}/contacts", [
            'email' => 'existing@example.com',
        ]);

        $response->assertOk();

        $contact->refresh();
        $this->assertCount(2, $contact->lists);
        $this->assertTrue($contact->lists->contains($list1));
        $this->assertTrue($contact->lists->contains($list2));
    }

    public function test_a_list_upsert_keeps_an_unsubscribe_that_an_admin_made(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'jane@example.com',
            'unsubscribed_at' => now()->subDay(),
            'unsubscribed_by_contact' => false,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/lists/{$list->id}/contacts", [
            'name' => 'Updated Name',
            'email' => 'jane@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.unsubscribed_at', fn ($value) => ! is_null($value));

        $contact->refresh();
        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertFalse($contact->unsubscribed_by_contact);
    }

    public function test_contact_attribute_catalog_updates_for_extra_attributes(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        Sanctum::actingAs($user, ['*']);

        $createResponse = $this->postJson("/api/v1/lists/{$list->id}/contacts", [
            'name' => 'Cataloged',
            'email' => 'catalog@example.com',
            'status' => 'active',
        ]);

        $createResponse->assertCreated();

        $this->assertDatabaseHas('contact_attributes', [
            'user_id' => $user->id,
            'name' => 'status',
        ]);

        $contact = Contact::where('email', 'catalog@example.com')->first();
        $this->assertNotNull($contact);

        $updateResponse = $this->putJson("/api/v1/contacts/{$contact->id}", [
            'plan' => 'pro',
        ]);

        $updateResponse->assertOk();

        $this->assertDatabaseHas('contact_attributes', [
            'user_id' => $user->id,
            'name' => 'plan',
        ]);
    }

    public function test_put_accepts_the_current_email_of_the_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['email' => 'same@example.com']);
        Sanctum::actingAs($user, ['*']);

        $this->putJson("/api/v1/contacts/{$contact->id}", [
            'email' => 'same@example.com',
            'name' => 'Same Email',
        ])->assertOk()->assertJsonPath('data.name', 'Same Email');
    }

    public function test_patch_accepts_the_current_email_of_the_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['email' => 'same@example.com']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/contacts/{$contact->id}", [
            'email' => 'same@example.com',
        ])->assertOk();
    }

    public function test_put_rejects_the_email_of_another_contact(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['email' => 'taken@example.com']);
        $contact = Contact::factory()->for($user)->create(['email' => 'mine@example.com']);
        Sanctum::actingAs($user, ['*']);

        $this->putJson("/api/v1/contacts/{$contact->id}", ['email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'The email has already been taken.']);
    }

    public function test_an_update_never_changes_the_subscription(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'sam@example.com',
            'unsubscribed_at' => now()->subDay(),
            'unsubscribed_by_contact' => false,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->putJson("/api/v1/contacts/{$contact->id}", ['name' => 'Sam Updated', 'plan' => 'pro'])
            ->assertOk()
            ->assertJsonPath('data.unsubscribed_at', fn ($value) => ! is_null($value));

        $this->patchJson("/api/v1/contacts/{$contact->id}", ['plan' => 'team'])
            ->assertOk()
            ->assertJsonPath('data.unsubscribed_at', fn ($value) => ! is_null($value));

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }

    public function test_subscribe_clears_an_unsubscribe_that_an_admin_made(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now()->subDay(),
            'unsubscribed_by_contact' => false,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/contacts/{$contact->id}/subscribe")
            ->assertOk()
            ->assertJsonPath('data.id', $contact->id)
            ->assertJsonPath('data.unsubscribed_at', null);

        $this->assertNull($contact->fresh()->unsubscribed_at);
    }

    public function test_subscribe_refuses_a_contact_that_unsubscribed_through_an_email_link(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now()->subDay(),
            'unsubscribed_by_contact' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/contacts/{$contact->id}/subscribe")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contact' => 'This contact unsubscribed from your emails. Only the contact can subscribe again.']);

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }

    public function test_subscribe_reports_an_opt_out_that_happens_during_the_request(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => now()->subDay(), 'unsubscribed_by_contact' => false]);
        Sanctum::actingAs($user, ['*']);
        $done = false;
        Contact::retrieved(function () use ($contact, &$done): void {
            if (! $done) {
                $done = true;
                Contact::withoutGlobalScopes()->whereKey($contact->id)->toBase()->update(['unsubscribed_by_contact' => true]);
            }
        });

        $this->postJson("/api/v1/contacts/{$contact->id}/subscribe")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contact' => 'This contact unsubscribed from your emails. Only the contact can subscribe again.']);

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }

    public function test_subscribe_refuses_a_blacklisted_address_without_a_confirmation(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'blocked@example.com',
            'unsubscribed_at' => now()->subDay(),
            'unsubscribed_by_contact' => false,
        ]);
        BlacklistedEmail::factory()->for($user)->create(['email' => 'blocked@example.com']);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/contacts/{$contact->id}/subscribe")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['remove_suppression' => 'Confirm that you remove the address from the suppression list.']);

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }

    public function test_subscribe_does_not_find_the_contact_of_another_client(): void
    {
        $contact = Contact::factory()->create(['unsubscribed_at' => now()->subDay()]);
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson("/api/v1/contacts/{$contact->id}/subscribe")->assertNotFound();

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }

    public function test_contact_remains_unsubscribed_when_flagged_by_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'keep@example.com',
            'unsubscribed_at' => now()->subDay(),
            'unsubscribed_by_contact' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson("/api/v1/contacts/{$contact->id}", [
            'name' => 'Keep Same',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.unsubscribed_at', fn ($value) => ! is_null($value));

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }

    public function test_user_cannot_resubscribe_contact_that_unsubscribed_themselves_via_upsert(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'self-unsub@example.com',
            'unsubscribed_at' => now()->subDay(),
            'unsubscribed_by_contact' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/lists/{$list->id}/contacts", [
            'name' => 'Trying to Resubscribe',
            'email' => 'self-unsub@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.unsubscribed_at', fn ($value) => ! is_null($value))
            ->assertJsonPath('data.unsubscribed_by_contact', true);

        $contact->refresh();
        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertTrue($contact->unsubscribed_by_contact);
    }

    public function test_blacklisted_email_cannot_be_created(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        BlacklistedEmail::factory()->for($user)->create([
            'email' => 'blocked@example.com',
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/lists/{$list->id}/contacts", [
            'email' => 'blocked@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_an_email_change_to_a_blacklisted_address_is_refused(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['email' => 'ada@example.com']);
        BlacklistedEmail::factory()->for($user)->create(['email' => 'blocked@example.com']);
        Sanctum::actingAs($user, ['*']);

        $this->putJson("/api/v1/contacts/{$contact->id}", ['email' => 'blocked@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'This email address has been blacklisted.']);

        $this->assertSame('ada@example.com', $contact->fresh()->email);
    }

    public function test_blacklisted_contact_remains_unsubscribed_when_upserting(): void
    {
        Carbon::setTestNow(now()->startOfSecond());
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'blocked@example.com',
            'unsubscribed_at' => now()->subDay(),
            'unsubscribed_by_contact' => false,
        ]);
        BlacklistedEmail::factory()->for($user)->create([
            'email' => 'blocked@example.com',
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/lists/{$list->id}/contacts", [
            'email' => 'blocked@example.com',
            'name' => 'Blocked',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.unsubscribed_at', fn ($value) => ! is_null($value));

        $this->assertEquals(now()->subDay()->toISOString(), optional($contact->fresh()->unsubscribed_at)->toISOString());
    }

    public function test_global_blacklist_blocks_contact_creation_for_all_users(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        BlacklistedEmail::factory()->global()->create([
            'email' => 'global-blocked@example.com',
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/lists/{$list->id}/contacts", [
            'email' => 'global-blocked@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_contact_can_be_unsubscribed(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'unsub@example.com',
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/contacts/{$contact->id}/unsubscribe");

        $response->assertOk()
            ->assertJsonPath('data.email', 'unsub@example.com')
            ->assertJsonPath('data.unsubscribed_at', fn ($value) => ! is_null($value))
            ->assertJsonPath('data.unsubscribed_by_contact', false);

        $contact->refresh();
        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertFalse($contact->unsubscribed_by_contact);
    }

    public function test_unsubscribe_preserves_existing_unsubscribed_at(): void
    {
        Carbon::setTestNow(now()->startOfSecond());
        $user = User::factory()->create();
        $originalUnsubDate = now()->subWeek();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'already-unsub@example.com',
            'unsubscribed_at' => $originalUnsubDate,
            'unsubscribed_by_contact' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/contacts/{$contact->id}/unsubscribe");

        $response->assertOk();

        $contact->refresh();
        $this->assertEquals($originalUnsubDate->toISOString(), $contact->unsubscribed_at->toISOString());
    }

    public function test_unsubscribe_keeps_the_opt_out_of_the_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now()->subWeek(),
            'unsubscribed_by_contact' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/contacts/{$contact->id}/unsubscribe")
            ->assertOk()
            ->assertJsonPath('data.unsubscribed_by_contact', true);

        $this->assertTrue($contact->fresh()->unsubscribed_by_contact);

        // A later update must not put the contact back on the list.
        $this->putJson("/api/v1/contacts/{$contact->id}", ['name' => 'Renamed'])->assertOk();

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }

    public function test_cannot_unsubscribe_other_users_contact(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherContact = Contact::factory()->for($otherUser)->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/contacts/{$otherContact->id}/unsubscribe");

        $response->assertNotFound();
    }

    public function test_contact_can_be_removed_from_list(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'remove@example.com',
        ]);
        $contact->lists()->attach($list->id);
        Sanctum::actingAs($user, ['*']);

        $response = $this->deleteJson("/api/v1/lists/{$list->id}/contacts/{$contact->id}");

        $response->assertOk()
            ->assertJsonPath('data.email', 'remove@example.com')
            ->assertJsonCount(0, 'data.lists');

        $contact->refresh();
        $this->assertFalse($contact->lists->contains($list));
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }

    public function test_contact_remains_in_other_lists_when_removed_from_one(): void
    {
        $user = User::factory()->create();
        $list1 = MailList::factory()->for($user)->create();
        $list2 = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'multilists@example.com',
        ]);
        $contact->lists()->attach([$list1->id, $list2->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->deleteJson("/api/v1/lists/{$list1->id}/contacts/{$contact->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.lists');

        $contact->refresh();
        $this->assertFalse($contact->lists->contains($list1));
        $this->assertTrue($contact->lists->contains($list2));
    }

    public function test_cannot_remove_contact_from_other_users_list(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherList = MailList::factory()->for($otherUser)->create();
        $contact = Contact::factory()->for($user)->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->deleteJson("/api/v1/lists/{$otherList->id}/contacts/{$contact->id}");

        $response->assertNotFound();
    }

    public function test_cannot_add_contact_to_other_users_list(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherList = MailList::factory()->for($otherUser)->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/lists/{$otherList->id}/contacts", [
            'email' => 'test@example.com',
        ]);

        $response->assertNotFound();
    }

    public function test_returns_404_when_list_not_found(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/lists/999999/contacts', [
            'email' => 'test@example.com',
        ]);

        $response->assertNotFound();
    }

    public function test_returns_404_when_contact_not_found_on_remove(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->deleteJson("/api/v1/lists/{$list->id}/contacts/999999");

        $response->assertNotFound();
    }
}
