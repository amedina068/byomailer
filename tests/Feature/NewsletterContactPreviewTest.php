<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MailList;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterContactPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_preview_contacts(): void
    {
        $response = $this->postJson(route('newsletters.contacts.preview'), [
            'filters' => [],
        ]);

        $response->assertUnauthorized();
    }

    public function test_it_returns_total_active_contacts_when_no_filters(): void
    {
        $user = User::factory()->create();
        Contact::factory()->count(3)->for($user)->create();
        Contact::factory()->for($user)->create(['unsubscribed_at' => now()]);
        Contact::factory()->create(); // another user's contact

        $response = $this->actingAs($user)->postJson(route('newsletters.contacts.preview'), [
            'filters' => [],
        ]);

        $response->assertOk()->assertJson(['count' => 3]);
    }

    public function test_it_excludes_unsubscribed_contacts(): void
    {
        $user = User::factory()->create();
        Contact::factory()->count(2)->for($user)->create();
        Contact::factory()->for($user)->create(['unsubscribed_at' => now()]);

        $response = $this->actingAs($user)->postJson(route('newsletters.contacts.preview'), [
            'filters' => [],
        ]);

        $response->assertOk()->assertJson(['count' => 2]);
    }

    public function test_it_counts_contacts_matching_list_filter(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $otherList = MailList::factory()->for($user)->create();

        $contactsInList = Contact::factory()->count(2)->for($user)->create();
        $contactsInList->each(fn (Contact $c) => $c->lists()->attach($list->id));

        $contactNotInList = Contact::factory()->for($user)->create();
        $contactNotInList->lists()->attach($otherList->id);

        $response = $this->actingAs($user)->postJson(route('newsletters.contacts.preview'), [
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        $response->assertOk()->assertJson(['count' => 2]);
    }

    public function test_it_counts_contacts_matching_tag_filter(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();

        $taggedContacts = Contact::factory()->count(2)->for($user)->create();
        $taggedContacts->each(fn (Contact $c) => $c->tags()->attach($tag->id));

        Contact::factory()->for($user)->create(); // no tag

        $response = $this->actingAs($user)->postJson(route('newsletters.contacts.preview'), [
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'tags',
                    'mode' => 'any',
                    'values' => [$tag->name],
                ],
            ],
        ]);

        $response->assertOk()->assertJson(['count' => 2]);
    }

    public function test_it_only_counts_the_authenticated_users_contacts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Contact::factory()->count(3)->for($user)->create();
        Contact::factory()->count(5)->for($otherUser)->create();

        $response = $this->actingAs($user)->postJson(route('newsletters.contacts.preview'), [
            'filters' => [],
        ]);

        $response->assertOk()->assertJson(['count' => 3]);
    }

    public function test_it_ignores_list_ids_belonging_to_other_users(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherList = MailList::factory()->for($otherUser)->create();

        Contact::factory()->count(2)->for($user)->create();

        // Passing another user's list ID should not reveal their data
        $response = $this->actingAs($user)->postJson(route('newsletters.contacts.preview'), [
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$otherList->id],
                ],
            ],
        ]);

        // The list doesn't belong to the user — the form request rejects the
        // cross-tenant reference outright rather than silently stripping it.
        $response->assertStatus(422)->assertJsonValidationErrors('filters.0.values');
    }
}
