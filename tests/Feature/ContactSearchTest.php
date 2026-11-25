<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContactSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_filters_by_name(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['name' => 'Alice Smith', 'email' => 'alice@example.com']);
        Contact::factory()->for($user)->create(['name' => 'Bob Jones', 'email' => 'bob@example.com']);

        $response = $this->actingAs($user)->get(route('contacts.index', ['search' => 'Alice']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Index')
            ->has('contacts.data', 1)
            ->where('contacts.data.0.name', 'Alice Smith')
            ->where('filters.search', 'Alice')
        );
    }

    public function test_search_filters_by_email(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['name' => 'Alice Smith', 'email' => 'alice@example.com']);
        Contact::factory()->for($user)->create(['name' => 'Bob Jones', 'email' => 'bob@example.com']);

        $response = $this->actingAs($user)->get(route('contacts.index', ['search' => 'bob@example']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Index')
            ->has('contacts.data', 1)
            ->where('contacts.data.0.email', 'bob@example.com')
        );
    }

    public function test_search_is_case_insensitive(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['name' => 'Alice Smith', 'email' => 'alice@example.com']);

        $response = $this->actingAs($user)->get(route('contacts.index', ['search' => 'alice smith']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Index')
            ->has('contacts.data', 1)
        );
    }

    public function test_search_returns_empty_when_no_match(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['name' => 'Alice Smith', 'email' => 'alice@example.com']);

        $response = $this->actingAs($user)->get(route('contacts.index', ['search' => 'xyz_no_match']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Index')
            ->has('contacts.data', 0)
            ->where('filters.search', 'xyz_no_match')
        );
    }

    public function test_search_does_not_return_other_users_contacts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Contact::factory()->for($user)->create(['name' => 'Alice Smith', 'email' => 'alice@example.com']);
        Contact::factory()->for($otherUser)->create(['name' => 'Alice Other', 'email' => 'alice-other@example.com']);

        $response = $this->actingAs($user)->get(route('contacts.index', ['search' => 'Alice']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Index')
            ->has('contacts.data', 1)
            ->where('contacts.data.0.name', 'Alice Smith')
        );
    }

    public function test_empty_search_returns_all_contacts(): void
    {
        $user = User::factory()->create();
        Contact::factory()->count(3)->for($user)->create();

        $response = $this->actingAs($user)->get(route('contacts.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Index')
            ->has('contacts.data', 3)
        );
    }

    public function test_search_filter_is_passed_to_frontend(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('contacts.index', ['search' => 'test']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Index')
            ->where('filters.search', 'test')
        );
    }
}
