<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NewsletterContactsPreviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_returns_count_for_filters(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $tag = Tag::factory()->for($user->client)->create(['name' => 'vip']);

        $matching = Contact::factory()->count(2)->for($user->client)->create();
        foreach ($matching as $contact) {
            $contact->tags()->attach($tag->id);
        }

        Contact::factory()->count(3)->for($user->client)->create();

        $response = $this->postJson('/api/v1/newsletters/contacts/preview', [
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'tags',
                    'mode' => 'any',
                    'values' => ['vip'],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson(['count' => 2]);
    }

    public function test_preview_with_no_filters_returns_total_active_contacts(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        Contact::factory()->count(5)->for($user->client)->create();

        $response = $this->postJson('/api/v1/newsletters/contacts/preview', []);

        $response->assertOk()
            ->assertJsonStructure(['count']);
    }

    public function test_preview_validates_filter_shape(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/newsletters/contacts/preview', [
            'filters' => [
                ['category' => 'wrong', 'mode' => 'any', 'values' => ['x']],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_preview_unauthenticated_returns_401(): void
    {
        $response = $this->postJson('/api/v1/newsletters/contacts/preview', []);

        $response->assertUnauthorized();
    }
}
