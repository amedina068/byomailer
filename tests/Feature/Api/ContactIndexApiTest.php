<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactIndexApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_paginated_contacts_for_authenticated_client(): void
    {
        $user = User::factory()->create();
        Contact::factory()->count(3)->for($user)->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/contacts');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'uuid', 'name', 'email', 'extra_attributes', 'unsubscribed_at', 'tags', 'lists', 'topics', 'sequences', 'created_at'],
                ],
                'meta' => ['path', 'per_page', 'next_cursor', 'prev_cursor'],
            ]);
    }

    public function test_index_isolates_contacts_across_clients(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Contact::factory()->for($user)->create(['email' => 'mine@example.com']);
        Contact::factory()->for($otherUser)->create(['email' => 'theirs@example.com']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/contacts');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'mine@example.com');
    }

    public function test_teammate_in_same_client_sees_shared_contacts(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create(['client_id' => $owner->client_id]);
        Contact::factory()->for($owner)->create(['email' => 'shared@example.com']);
        Sanctum::actingAs($teammate, ['*']);

        $response = $this->getJson('/api/v1/contacts');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'shared@example.com');
    }

    public function test_index_excludes_unsubscribed_by_default(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['email' => 'active@example.com']);
        Contact::factory()->for($user)->create([
            'email' => 'gone@example.com',
            'unsubscribed_at' => now(),
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/contacts');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'active@example.com');
    }

    public function test_index_can_include_unsubscribed(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['email' => 'active@example.com']);
        Contact::factory()->for($user)->create([
            'email' => 'gone@example.com',
            'unsubscribed_at' => now(),
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/contacts?include_unsubscribed=1');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_lists(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $match = Contact::factory()->for($user)->create(['email' => 'in-list@example.com']);
        $match->lists()->attach($list->id);
        Contact::factory()->for($user)->create(['email' => 'out-of-list@example.com']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/v1/contacts?lists[]={$list->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'in-list@example.com');
    }

    public function test_index_filters_by_tags(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'vip']);
        $match = Contact::factory()->for($user)->create(['email' => 'vip@example.com']);
        $match->tags()->attach($tag->id);
        Contact::factory()->for($user)->create(['email' => 'other@example.com']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/v1/contacts?tags[]={$tag->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'vip@example.com');
    }

    public function test_index_filters_by_topics(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $topic = Topic::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'list_id' => $list->id,
        ]);
        $subscribed = Contact::factory()->for($user)->create(['email' => 'sub@example.com']);
        $subscribed->topics()->attach($topic->id, ['subscribed_at' => now()]);
        $unsubscribed = Contact::factory()->for($user)->create(['email' => 'unsub@example.com']);
        $unsubscribed->topics()->attach($topic->id, ['subscribed_at' => now()->subDay(), 'unsubscribed_at' => now()]);
        Contact::factory()->for($user)->create(['email' => 'none@example.com']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/v1/contacts?topics[]={$topic->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'sub@example.com');
    }

    public function test_index_filters_by_search(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['name' => 'John Doe', 'email' => 'john@example.com']);
        Contact::factory()->for($user)->create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/contacts?search=john');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'john@example.com');
    }

    public function test_index_includes_sequences_in_response(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Onboarding']);
        $contact = Contact::factory()->for($user)->create();
        $contact->sequences()->attach($sequence->id);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/contacts');

        $response->assertOk()
            ->assertJsonCount(1, 'data.0.sequences')
            ->assertJsonPath('data.0.sequences.0.name', 'Onboarding');
    }

    public function test_index_includes_topics_in_response(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $topic = Topic::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'list_id' => $list->id,
            'name' => 'Product Updates',
        ]);
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list->id);
        $contact->topics()->attach($topic->id, ['subscribed_at' => now()]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/contacts');

        $response->assertOk()
            ->assertJsonCount(1, 'data.0.topics')
            ->assertJsonPath('data.0.topics.0.name', 'Product Updates')
            ->assertJsonPath('data.0.topics.0.list_id', $list->id);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/contacts');

        $response->assertUnauthorized();
    }
}
