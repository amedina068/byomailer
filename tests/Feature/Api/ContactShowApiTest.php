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

class ContactShowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_contact_with_tags_lists_and_topics(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'vip']);
        $topic = Topic::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'list_id' => $list->id,
            'name' => 'News',
        ]);
        $contact = Contact::factory()->for($user)->create(['email' => 'c@example.com']);
        $contact->lists()->attach($list->id);
        $contact->tags()->attach($tag->id);
        $contact->topics()->attach($topic->id, ['subscribed_at' => now()]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/v1/contacts/{$contact->id}");

        $response->assertOk()
            ->assertJsonPath('data.email', 'c@example.com')
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonCount(1, 'data.lists')
            ->assertJsonCount(1, 'data.topics')
            ->assertJsonPath('data.topics.0.name', 'News');
    }

    public function test_show_returns_the_sequences_of_the_contact(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Onboarding']);
        Sequence::factory()->for($user)->create(['name' => 'Other']);
        $contact = Contact::factory()->for($user)->create();
        $contact->sequences()->attach($sequence->id);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/v1/contacts/{$contact->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.sequences')
            ->assertJsonPath('data.sequences.0.id', $sequence->id)
            ->assertJsonPath('data.sequences.0.name', 'Onboarding');
    }

    public function test_show_returns_404_for_other_client_contact(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherContact = Contact::factory()->for($otherUser)->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/v1/contacts/{$otherContact->id}");

        $response->assertNotFound();
    }

    public function test_show_returns_contact_for_teammate_in_same_client(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create(['client_id' => $owner->client_id]);
        $contact = Contact::factory()->for($owner)->create(['email' => 'shared@example.com']);
        Sanctum::actingAs($teammate, ['*']);

        $response = $this->getJson("/api/v1/contacts/{$contact->id}");

        $response->assertOk()->assertJsonPath('data.email', 'shared@example.com');
    }

    public function test_show_requires_authentication(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();

        $response = $this->getJson("/api/v1/contacts/{$contact->id}");

        $response->assertUnauthorized();
    }
}
