<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContactIndexPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contacts_index_returns_paginated_results(): void
    {
        $user = User::factory()->create();
        Contact::factory()->count(30)->for($user)->create();

        $response = $this->actingAs($user)->get(route('contacts.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Index')
            ->has('contacts.data', 25)
            ->has('contacts.links')
            ->where('contacts.current_page', 1)
            ->where('contacts.last_page', 2)
            ->where('contacts.total', 30)
        );
    }

    public function test_contacts_index_respects_page_parameter(): void
    {
        $user = User::factory()->create();
        Contact::factory()->count(30)->for($user)->create();

        $response = $this->actingAs($user)->get(route('contacts.index', ['page' => 2]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Index')
            ->has('contacts.data', 5)
            ->where('contacts.current_page', 2)
        );
    }

    public function test_contacts_pagination_preserves_filter_query_strings(): void
    {
        $user = User::factory()->create();
        Contact::factory()->count(30)->for($user)->create();

        $response = $this->actingAs($user)->get(route('contacts.index', [
            'include_unsubscribed' => '1',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Index')
            ->where('contacts.current_page', 1)
            ->where(
                'contacts.next_page_url',
                fn ($url) => str_contains($url, 'include_unsubscribed=1')
            )
        );
    }
}
