<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TagManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_tags_index(): void
    {
        $user = User::factory()->create();
        Tag::factory()->for($user)->create(['name' => 'VIP']);

        $response = $this->actingAs($user)->get(route('tags.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('tags/Index')
            ->has('tags', 1)
            ->where('tags.0.name', 'VIP')
        );
    }

    public function test_a_teammate_sees_the_tags_of_the_client(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        Tag::factory()->for($owner)->create(['name' => 'VIP']);
        Tag::factory()->create(['name' => 'Other client']);

        $response = $this->actingAs($teammate)->get(route('tags.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('tags', 1)
            ->where('tags.0.name', 'VIP')
        );
    }

    public function test_user_can_create_tag_via_form(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('tags.store'), [
            'name' => 'VIP',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('createdTag.name', 'VIP');

        $this->assertDatabaseHas('tags', [
            'client_id' => $user->client_id,
            'user_id' => $user->id,
            'name' => 'VIP',
        ]);
    }

    public function test_tag_creation_validates_name_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('tags.store'), [
            'name' => '',
        ]);

        $response->assertSessionHasErrors(['name' => 'The name field is required.']);
    }

    public function test_a_teammate_cannot_create_a_tag_name_that_the_client_has(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        Tag::factory()->for($owner)->create(['name' => 'Existing']);

        $response = $this->actingAs($teammate)->post(route('tags.store'), [
            'name' => 'Existing',
        ]);

        $response->assertSessionHasErrors(['name' => 'The name has already been taken.']);
        $this->assertSame(1, Tag::query()->where('name', 'Existing')->count());
    }

    public function test_another_client_can_use_the_same_tag_name(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Tag::factory()->for($userA)->create(['name' => 'Shared']);

        $response = $this->actingAs($userB)->post(route('tags.store'), [
            'name' => 'Shared',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('createdTag.name', 'Shared');
    }
}
