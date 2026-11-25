<?php

namespace Tests\Feature;

use App\Models\ContactAttribute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AttributeDefaultControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('attributes.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_only_own_client_attributes_ordered_by_name(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        ContactAttribute::factory()->withDefault('Acme')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);
        ContactAttribute::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'city',
        ]);
        ContactAttribute::factory()->create([
            'user_id' => $otherUser->id,
            'client_id' => $otherUser->client_id,
            'name' => 'secret',
        ]);

        $this->actingAs($user)
            ->get(route('attributes.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('attributes/Index')
                ->has('attributes', 2)
                ->where('attributes.0.name', 'city')
                ->where('attributes.0.default_value', null)
                ->where('attributes.1.name', 'company')
                ->where('attributes.1.default_value', 'Acme'),
            );
    }

    public function test_update_persists_default_value(): void
    {
        $user = User::factory()->create();
        $attribute = ContactAttribute::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);

        $this->actingAs($user)
            ->patch(route('attributes.update', $attribute), ['default_value' => 'Acme Corp'])
            ->assertRedirect();

        $this->assertSame('Acme Corp', $attribute->fresh()->default_value);
    }

    public function test_update_normalizes_empty_string_to_null(): void
    {
        $user = User::factory()->create();
        $attribute = ContactAttribute::factory()->withDefault('Old')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);

        $this->actingAs($user)
            ->patch(route('attributes.update', $attribute), ['default_value' => ''])
            ->assertRedirect();

        $this->assertNull($attribute->fresh()->default_value);
    }

    public function test_update_preserves_literal_zero_string(): void
    {
        $user = User::factory()->create();
        $attribute = ContactAttribute::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'coupon',
        ]);

        $this->actingAs($user)
            ->patch(route('attributes.update', $attribute), ['default_value' => '0'])
            ->assertRedirect();

        $this->assertSame('0', $attribute->fresh()->default_value);
    }

    public function test_update_rejects_values_over_255_characters(): void
    {
        $user = User::factory()->create();
        $attribute = ContactAttribute::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);

        $this->actingAs($user)
            ->patch(route('attributes.update', $attribute), ['default_value' => str_repeat('x', 256)])
            ->assertSessionHasErrors('default_value');
    }

    public function test_update_cannot_touch_another_clients_attribute(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $attribute = ContactAttribute::factory()->create([
            'user_id' => $otherUser->id,
            'client_id' => $otherUser->client_id,
            'name' => 'company',
        ]);

        $this->actingAs($user)
            ->patch(route('attributes.update', $attribute), ['default_value' => 'Hacked'])
            ->assertNotFound();

        $this->assertNull($attribute->fresh()->default_value);
    }

    public function test_cleared_default_is_excluded_from_global_defaults_map(): void
    {
        $user = User::factory()->create();
        $attribute = ContactAttribute::factory()->withDefault('Old')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);

        $this->actingAs($user)
            ->patch(route('attributes.update', $attribute), ['default_value' => ''])
            ->assertRedirect();

        $this->assertNull(
            ContactAttribute::withoutGlobalScopes()
                ->where('client_id', $user->client_id)
                ->where('name', 'company')
                ->value('default_value'),
        );
    }
}
