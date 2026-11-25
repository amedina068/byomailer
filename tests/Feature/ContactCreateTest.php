<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContactCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_is_accessible_to_authenticated_users(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('contacts.create'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('contacts/Create'));
    }

    public function test_create_page_requires_authentication(): void
    {
        $response = $this->get(route('contacts.create'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_create_contact_with_email_only(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('contacts.store'), [
            'email' => 'subscriber@example.com',
        ]);

        $contact = Contact::where('email', 'subscriber@example.com')->first();

        $this->assertNotNull($contact);
        $this->assertSame($user->id, $contact->user_id);
        $this->assertNull($contact->name);

        $response->assertRedirect(route('contacts.show', $contact));
    }

    public function test_user_can_create_contact_with_email_and_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('contacts.store'), [
            'email' => 'john@example.com',
            'name' => 'John Doe',
        ]);

        $contact = Contact::where('email', 'john@example.com')->first();

        $this->assertNotNull($contact);
        $this->assertSame($user->id, $contact->user_id);
        $this->assertSame('John Doe', $contact->name);

        $response->assertRedirect(route('contacts.show', $contact));
    }

    public function test_email_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('contacts.create'))
            ->post(route('contacts.store'), [
                'name' => 'John Doe',
            ]);

        $response->assertRedirect(route('contacts.create'));
        $response->assertSessionHasErrors('email');

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_email_must_be_valid(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('contacts.create'))
            ->post(route('contacts.store'), [
                'email' => 'not-an-email',
            ]);

        $response->assertRedirect(route('contacts.create'));
        $response->assertSessionHasErrors('email');

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_email_must_be_unique_per_user(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['email' => 'existing@example.com']);

        $response = $this->actingAs($user)
            ->from(route('contacts.create'))
            ->post(route('contacts.store'), [
                'email' => 'existing@example.com',
            ]);

        $response->assertRedirect(route('contacts.create'));
        $response->assertSessionHasErrors(['email' => 'A contact with this email already exists.']);

        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_same_email_can_exist_for_different_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Contact::factory()->for($user1)->create(['email' => 'shared@example.com']);

        $response = $this->actingAs($user2)->post(route('contacts.store'), [
            'email' => 'shared@example.com',
        ]);

        $contact = Contact::where('email', 'shared@example.com')
            ->where('user_id', $user2->id)
            ->first();

        $this->assertNotNull($contact);
        $response->assertRedirect(route('contacts.show', $contact));

        $this->assertDatabaseCount('contacts', 2);
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->post(route('contacts.store'), [
            'email' => 'subscriber@example.com',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_name_max_length_is_enforced(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('contacts.create'))
            ->post(route('contacts.store'), [
                'email' => 'valid@example.com',
                'name' => str_repeat('a', 256),
            ]);

        $response->assertRedirect(route('contacts.create'));
        $response->assertSessionHasErrors('name');

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_contacts_index_is_accessible(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('contacts.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('contacts/Index'));
    }
}
