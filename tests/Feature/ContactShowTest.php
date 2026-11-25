<?php

namespace Tests\Feature;

use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\ContactAttribute;
use App\Models\ContactMail;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Tag;
use App\Models\User;
use App\Services\SunsetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContactShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_page_returns_all_tags_and_lists(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('contacts.show', $contact));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Show')
            ->has('allTags', 1)
            ->has('allLists', 1)
            ->has('contactAttributes')
        );
    }

    public function test_show_page_excludes_pending_mails(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletterA = Newsletter::factory()->for($user)->create();
        $newsletterB = Newsletter::factory()->for($user)->create();

        $sent = ContactMail::factory()->for($contact)->forNewsletter($newsletterA)->create([
            'status' => 'sent',
            'sent_at' => now()->subHour(),
        ]);
        ContactMail::factory()->for($contact)->forNewsletter($newsletterB)->create([
            'status' => 'pending',
            'sent_at' => null,
            'scheduled_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($user)->get(route('contacts.show', $contact));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Show')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('mails', 1)
                ->where('mails.0.id', $sent->id)
            )
        );
    }

    public function test_user_can_update_contact_name(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'name' => 'Old Name',
            'email' => 'test@example.com',
        ]);

        $response = $this->actingAs($user)->patch(route('contacts.update', $contact), [
            'name' => 'New Name',
            'email' => 'test@example.com',
        ]);

        $response->assertRedirect();
        $this->assertSame('New Name', $contact->fresh()->name);
    }

    public function test_user_can_update_contact_email(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'name' => 'Test',
            'email' => 'old@example.com',
        ]);

        $response = $this->actingAs($user)->patch(route('contacts.update', $contact), [
            'name' => 'Test',
            'email' => 'new@example.com',
        ]);

        $response->assertRedirect();
        $this->assertSame('new@example.com', $contact->fresh()->email);
    }

    public function test_update_validates_email_uniqueness(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['email' => 'taken@example.com']);
        $contact = Contact::factory()->for($user)->create(['email' => 'mine@example.com']);

        $response = $this->actingAs($user)
            ->from(route('contacts.show', $contact))
            ->patch(route('contacts.update', $contact), [
                'name' => 'Test',
                'email' => 'taken@example.com',
            ]);

        $response->assertRedirect(route('contacts.show', $contact));
        $response->assertSessionHasErrors('email');
        $this->assertSame('mine@example.com', $contact->fresh()->email);
    }

    public function test_user_can_update_email_to_same_value(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['email' => 'same@example.com']);

        $response = $this->actingAs($user)->patch(route('contacts.update', $contact), [
            'name' => 'Updated',
            'email' => 'same@example.com',
        ]);

        $response->assertRedirect();
        $this->assertSame('Updated', $contact->fresh()->name);
    }

    public function test_user_cannot_update_another_clients_contact(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $contact = Contact::factory()->for($user1)->create(['email' => 'test@example.com']);

        $response = $this->actingAs($user2)->patch(route('contacts.update', $contact), [
            'name' => 'Hacked',
            'email' => 'test@example.com',
        ]);

        $response->assertNotFound();
    }

    public function test_user_can_sync_tags(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $tag1 = Tag::factory()->for($user)->create();
        $tag2 = Tag::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('contacts.tags.sync', $contact), [
            'tag_ids' => [$tag1->id, $tag2->id],
        ]);

        $response->assertRedirect();
        $this->assertCount(2, $contact->fresh()->tags);
    }

    public function test_sync_tags_ignores_other_clients_tags(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $contact = Contact::factory()->for($user1)->create();
        $otherTag = Tag::factory()->for($user2)->create();

        $response = $this->actingAs($user1)->post(route('contacts.tags.sync', $contact), [
            'tag_ids' => [$otherTag->id],
        ]);

        $response->assertRedirect();
        $this->assertCount(0, $contact->fresh()->tags);
    }

    public function test_user_can_sync_lists(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('contacts.lists.sync', $contact), [
            'list_ids' => [$list->id],
        ]);

        $response->assertRedirect();
        $this->assertCount(1, $contact->fresh()->lists);
    }

    public function test_sync_lists_ignores_other_clients_lists(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $contact = Contact::factory()->for($user1)->create();
        $otherList = MailList::factory()->for($user2)->create();

        $response = $this->actingAs($user1)->post(route('contacts.lists.sync', $contact), [
            'list_ids' => [$otherList->id],
        ]);

        $response->assertRedirect();
        $this->assertCount(0, $contact->fresh()->lists);
    }

    public function test_user_can_add_attribute(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => []]);

        $response = $this->actingAs($user)->post(route('contacts.attributes.store', $contact), [
            'attribute_name' => 'company',
            'attribute_value' => 'Acme Inc',
        ]);

        $response->assertRedirect();
        $this->assertSame('Acme Inc', $contact->fresh()->extra_attributes['company']);
    }

    public function test_add_attribute_creates_contact_attribute_definition(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => []]);

        $this->actingAs($user)->post(route('contacts.attributes.store', $contact), [
            'attribute_name' => 'company',
            'attribute_value' => 'Acme Inc',
        ]);

        $this->assertDatabaseHas('contact_attributes', [
            'user_id' => $user->id,
            'name' => 'company',
        ]);
    }

    public function test_add_attribute_validates_required_fields(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->from(route('contacts.show', $contact))
            ->post(route('contacts.attributes.store', $contact), [
                'attribute_name' => '',
                'attribute_value' => '',
            ]);

        $response->assertRedirect(route('contacts.show', $contact));
        $response->assertSessionHasErrors(['attribute_name', 'attribute_value']);
    }

    public function test_update_requires_authentication(): void
    {
        $contact = Contact::factory()->create();

        $response = $this->patch(route('contacts.update', $contact), [
            'name' => 'Test',
            'email' => 'test@example.com',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_sync_tags_requires_authentication(): void
    {
        $contact = Contact::factory()->create();

        $response = $this->post(route('contacts.tags.sync', $contact), [
            'tag_ids' => [1],
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_sync_lists_requires_authentication(): void
    {
        $contact = Contact::factory()->create();

        $response = $this->post(route('contacts.lists.sync', $contact), [
            'list_ids' => [1],
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_show_page_passes_contact_attributes(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        ContactAttribute::factory()->for($user)->create(['name' => 'company']);

        $response = $this->actingAs($user)->get(route('contacts.show', $contact));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Show')
            ->where('contactAttributes', ['company'])
        );
    }

    public function test_user_can_update_attribute(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'extra_attributes' => ['company' => 'Old Corp'],
        ]);

        $response = $this->actingAs($user)->patch(
            route('contacts.attributes.update', [$contact, 'company']),
            ['attribute_value' => 'New Corp'],
        );

        $response->assertRedirect();
        $this->assertSame('New Corp', $contact->fresh()->extra_attributes['company']);
    }

    public function test_update_attribute_returns_404_for_missing_key(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'extra_attributes' => ['company' => 'Acme'],
        ]);

        $response = $this->actingAs($user)->patch(
            route('contacts.attributes.update', [$contact, 'nonexistent']),
            ['attribute_value' => 'value'],
        );

        $response->assertNotFound();
    }

    public function test_update_attribute_validates_value(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'extra_attributes' => ['company' => 'Acme'],
        ]);

        $response = $this->actingAs($user)
            ->from(route('contacts.show', $contact))
            ->patch(
                route('contacts.attributes.update', [$contact, 'company']),
                ['attribute_value' => ''],
            );

        $response->assertRedirect(route('contacts.show', $contact));
        $response->assertSessionHasErrors('attribute_value');
    }

    public function test_user_can_remove_attribute(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'extra_attributes' => ['company' => 'Acme', 'city' => 'NYC'],
        ]);

        $response = $this->actingAs($user)->delete(
            route('contacts.attributes.destroy', [$contact, 'company']),
        );

        $response->assertRedirect();
        $fresh = $contact->fresh();
        $this->assertArrayNotHasKey('company', $fresh->extra_attributes);
        $this->assertSame('NYC', $fresh->extra_attributes['city']);
    }

    public function test_remove_attribute_returns_404_for_missing_key(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'extra_attributes' => ['company' => 'Acme'],
        ]);

        $response = $this->actingAs($user)->delete(
            route('contacts.attributes.destroy', [$contact, 'nonexistent']),
        );

        $response->assertNotFound();
    }

    public function test_user_cannot_update_attribute_on_another_clients_contact(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $contact = Contact::factory()->for($user1)->create([
            'extra_attributes' => ['company' => 'Acme'],
        ]);

        $response = $this->actingAs($user2)->patch(
            route('contacts.attributes.update', [$contact, 'company']),
            ['attribute_value' => 'Hacked'],
        );

        $response->assertNotFound();
    }

    public function test_user_can_unsubscribe_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => null,
        ]);

        $response = $this->actingAs($user)->post(route('contacts.unsubscribe', $contact));

        $response->assertRedirect();
        $fresh = $contact->fresh();
        $this->assertNotNull($fresh->unsubscribed_at);
        $this->assertFalse($fresh->unsubscribed_by_contact);
    }

    public function test_unsubscribe_cancels_pending_mailings(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => null,
        ]);
        $pendingMail = ContactMail::factory()->for($contact)->create([
            'status' => 'pending',
            'sent_at' => null,
            'unsubscribed_at' => null,
        ]);

        $this->actingAs($user)->post(route('contacts.unsubscribe', $contact));

        $this->assertDatabaseMissing('contact_mails', ['id' => $pendingMail->id]);
    }

    public function test_user_can_subscribe_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => false,
        ]);

        $response = $this->actingAs($user)->post(route('contacts.subscribe', $contact));

        $response->assertRedirect();
        $fresh = $contact->fresh();
        $this->assertNull($fresh->unsubscribed_at);
        $this->assertFalse($fresh->unsubscribed_by_contact);
    }

    public function test_subscribe_asks_for_a_confirmation_before_it_removes_a_bounce_suppression(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['email' => 'Bounced@Example.com', 'unsubscribed_at' => now()]);
        $suppression = BlacklistedEmail::factory()->for($user)->create(['email' => 'bounced@example.com', 'reason' => 'bounce']);

        $this->actingAs($user)->post(route('contacts.subscribe', $contact))
            ->assertSessionHasErrors(['remove_suppression' => 'Confirm that you remove the address from the suppression list.']);

        $this->assertModelExists($suppression);
        $this->assertNotNull($contact->fresh()->unsubscribed_at);

        $this->actingAs($user)->post(route('contacts.subscribe', $contact), ['remove_suppression' => true])
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($suppression);
        $this->assertNull($contact->fresh()->unsubscribed_at);
    }

    public function test_subscribe_refuses_an_address_that_complained(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['email' => 'angry@example.com', 'unsubscribed_at' => now()]);
        $suppression = BlacklistedEmail::factory()->for($user)->create(['email' => 'angry@example.com', 'reason' => 'complaint']);

        $this->actingAs($user)->post(route('contacts.subscribe', $contact), ['remove_suppression' => true])
            ->assertSessionHasErrors(['contact' => 'The address is on the suppression list after a complaint, or the server operator blocked it. BYOMailer cannot mail it again.']);

        $this->assertModelExists($suppression);
        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }

    public function test_subscribe_refuses_an_address_on_the_global_suppression_list(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['email' => 'global@example.com', 'unsubscribed_at' => now()]);
        $suppression = BlacklistedEmail::factory()->global()->create(['email' => 'global@example.com', 'reason' => 'bounce']);

        $this->actingAs($user)->post(route('contacts.subscribe', $contact), ['remove_suppression' => true])
            ->assertSessionHasErrors('contact');

        $this->assertModelExists($suppression);
    }

    public function test_show_shares_the_suppression_of_the_address(): void
    {
        $user = User::factory()->create();
        $bounced = Contact::factory()->for($user)->create(['email' => 'bounced@example.com', 'unsubscribed_at' => now()]);
        BlacklistedEmail::factory()->for($user)->create(['email' => 'bounced@example.com', 'reason' => 'soft_bounce']);
        $complained = Contact::factory()->for($user)->create(['email' => 'angry@example.com', 'unsubscribed_at' => now()]);
        BlacklistedEmail::factory()->for($user)->create(['email' => 'angry@example.com', 'reason' => 'complaint']);
        $clean = Contact::factory()->for($user)->create();

        $this->actingAs($user)->get(route('contacts.show', $bounced))
            ->assertInertia(fn (Assert $page) => $page
                ->where('suppression', ['reason' => 'soft_bounce', 'can_remove' => true]));

        $this->actingAs($user)->get(route('contacts.show', $complained))
            ->assertInertia(fn (Assert $page) => $page
                ->where('suppression', ['reason' => 'complaint', 'can_remove' => false]));

        $this->actingAs($user)->get(route('contacts.show', $clean))
            ->assertInertia(fn (Assert $page) => $page->where('suppression', null));
    }

    public function test_user_cannot_subscribe_contact_who_unsubscribed_themselves(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ]);

        $response = $this->actingAs($user)->post(route('contacts.subscribe', $contact));

        $response->assertSessionHasErrors([
            'contact' => 'This contact unsubscribed from your emails. Only the contact can subscribe again.',
        ]);
        $fresh = $contact->fresh();
        $this->assertNotNull($fresh->unsubscribed_at);
        $this->assertTrue($fresh->unsubscribed_by_contact);
    }

    public function test_subscribe_reports_an_opt_out_that_happens_during_the_request(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => now(), 'unsubscribed_by_contact' => false]);
        $this->optOutAfterTheFirstRead($contact);

        $this->actingAs($user)->post(route('contacts.subscribe', $contact))
            ->assertSessionHasErrors(['contact' => 'This contact unsubscribed from your emails. Only the contact can subscribe again.']);

        $fresh = $contact->fresh();
        $this->assertNotNull($fresh->unsubscribed_at);
        $this->assertTrue($fresh->unsubscribed_by_contact);
    }

    public function test_subscribe_keeps_the_suppression_when_the_contact_opts_out_during_the_request(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['email' => 'bounced@example.com', 'unsubscribed_at' => now()]);
        $suppression = BlacklistedEmail::factory()->for($user)->create(['email' => 'bounced@example.com', 'reason' => 'bounce']);
        BlacklistedEmail::deleted(fn () => Contact::withoutGlobalScopes()->whereKey($contact->id)->toBase()->update(['unsubscribed_by_contact' => true]));

        $this->actingAs($user)->post(route('contacts.subscribe', $contact), ['remove_suppression' => true])
            ->assertSessionHasErrors('contact');

        $this->assertModelExists($suppression);
        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function attributeWrites(): array
    {
        return ['store' => ['store'], 'update' => ['update'], 'destroy' => ['destroy']];
    }

    #[DataProvider('attributeWrites')]
    public function test_an_attribute_write_keeps_an_attribute_that_another_request_saved(string $write): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => ['city' => 'Lima']]);
        $done = false;
        Contact::retrieved(function () use ($contact, &$done): void {
            if (! $done) {
                $done = true;
                Contact::withoutGlobalScopes()->whereKey($contact->id)->toBase()->update(['extra_attributes' => json_encode(['city' => 'Lima', 'plan' => 'pro'])]);
            }
        });

        $response = match ($write) {
            'store' => $this->actingAs($user)->post(route('contacts.attributes.store', $contact), ['attribute_name' => 'role', 'attribute_value' => 'admin']),
            'update' => $this->actingAs($user)->patch(route('contacts.attributes.update', [$contact, 'city']), ['attribute_value' => 'Quito']),
            'destroy' => $this->actingAs($user)->delete(route('contacts.attributes.destroy', [$contact, 'city'])),
        };

        $response->assertRedirect();

        $this->assertSame('pro', $contact->fresh()->extra_attributes['plan'] ?? null);
    }

    public function test_an_attribute_value_stays_a_string_of_at_most_255_characters(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();

        $this->actingAs($user)->post(route('contacts.attributes.store', $contact), ['attribute_name' => 'notes', 'attribute_value' => str_repeat('a', 256)])
            ->assertSessionHasErrors('attribute_value');
        $this->actingAs($user)->post(route('contacts.attributes.store', $contact), ['attribute_name' => 'notes', 'attribute_value' => ['a']])
            ->assertSessionHasErrors('attribute_value');

        $this->assertArrayNotHasKey('notes', $contact->fresh()->extra_attributes ?? []);
    }

    public function test_unsubscribe_keeps_the_opt_out_of_the_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now()->subWeek(),
            'unsubscribed_by_contact' => true,
        ]);

        $this->actingAs($user)->post(route('contacts.unsubscribe', $contact))->assertRedirect();

        $this->assertTrue($contact->fresh()->unsubscribed_by_contact);
    }

    public function test_unsubscribe_requires_authentication(): void
    {
        $contact = Contact::factory()->create();

        $response = $this->post(route('contacts.unsubscribe', $contact));

        $response->assertRedirect(route('login'));
    }

    public function test_subscribe_requires_authentication(): void
    {
        $contact = Contact::factory()->create();

        $response = $this->post(route('contacts.subscribe', $contact));

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_reactivate_a_sunset_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'sunset_at' => now(),
            'last_engaged_at' => now()->subDays(100),
        ]);

        $this->actingAs($user)
            ->post(route('contacts.reactivate', $contact))
            ->assertRedirect();

        $fresh = $contact->fresh();
        $this->assertNull($fresh->sunset_at);
        $this->assertNull($fresh->unsubscribed_at);
        $this->assertTrue($fresh->last_engaged_at->equalTo($contact->last_engaged_at));
    }

    public function test_reactivate_does_not_clear_an_existing_unsubscribe(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'sunset_at' => now(),
            'unsubscribed_at' => now()->subDay(),
        ]);

        $this->actingAs($user)->post(route('contacts.reactivate', $contact));

        $fresh = $contact->fresh();
        $this->assertNull($fresh->sunset_at);
        $this->assertNotNull($fresh->unsubscribed_at);
    }

    public function test_reactivate_forgets_the_sunset_preview_cache(): void
    {
        $user = User::factory()->create();
        $user->client->forceFill(['sunset_inactive_days' => 90])->save();
        $contact = Contact::factory()->for($user)->create(['sunset_at' => now()]);
        $cacheKey = SunsetPolicy::previewCacheKey($user->client_id, 90);
        Cache::put($cacheKey, 5, 600);

        $this->actingAs($user)->post(route('contacts.reactivate', $contact));

        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_reactivate_of_another_clients_contact_is_not_found(): void
    {
        $user = User::factory()->create();
        $otherContact = Contact::factory()->create(['sunset_at' => now()]);

        $this->actingAs($user)
            ->post(route('contacts.reactivate', $otherContact))
            ->assertNotFound();

        $this->assertNotNull($otherContact->fresh()->sunset_at);
    }

    public function test_reactivate_requires_authentication(): void
    {
        $contact = Contact::factory()->create(['sunset_at' => now()]);

        $this->post(route('contacts.reactivate', $contact))->assertRedirect(route('login'));
    }

    public function test_show_page_exposes_the_sunset_date(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['sunset_at' => now()]);

        $this->actingAs($user)
            ->get(route('contacts.show', $contact))
            ->assertInertia(fn (Assert $page) => $page
                ->component('contacts/Show')
                ->whereNot('contact.sunset_at', null)
                ->where('contact.unsubscribed_at', null)
            );
    }

    /**
     * Mark the contact as opted out by itself right after the first read of
     * the row. This is an unsubscribe click between the read and the write.
     */
    private function optOutAfterTheFirstRead(Contact $contact): void
    {
        $done = false;

        Contact::retrieved(function (Contact $retrieved) use ($contact, &$done): void {
            if ($done || $retrieved->id !== $contact->id) {
                return;
            }

            $done = true;
            Contact::withoutGlobalScopes()->whereKey($contact->id)->toBase()->update(['unsubscribed_by_contact' => true]);
        });
    }
}
