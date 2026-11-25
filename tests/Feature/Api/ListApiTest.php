<?php

namespace Tests\Feature\Api;

use App\Models\MailList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_can_be_listed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $otherList = MailList::factory()->create();
        MailList::factory()->for($user)->create(['name' => 'Primary']);

        $response = $this->getJson('/api/v1/lists');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Primary')
            ->assertJsonMissing(['name' => $otherList->name]);
    }

    public function test_list_can_be_created(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/lists', ['name' => 'Newsletter Audience']);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Newsletter Audience');

        $this->assertDatabaseHas('lists', [
            'user_id' => $user->id,
            'name' => 'Newsletter Audience',
        ]);
    }

    public function test_list_can_be_updated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $list = MailList::factory()->for($user)->create(['name' => 'Original']);

        $response = $this->patchJson("/api/v1/lists/{$list->id}", ['name' => 'Renamed']);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->assertDatabaseHas('lists', [
            'id' => $list->id,
            'name' => 'Renamed',
        ]);
    }

    public function test_list_can_be_deleted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $list = MailList::factory()->for($user)->create();

        $response = $this->deleteJson("/api/v1/lists/{$list->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('lists', ['id' => $list->id]);
    }

    public function test_user_cannot_access_other_users_lists(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $otherList = MailList::factory()->for($otherUser)->create();

        $updateResponse = $this->patchJson("/api/v1/lists/{$otherList->id}", ['name' => 'Hacked']);
        $updateResponse->assertNotFound();

        $deleteResponse = $this->deleteJson("/api/v1/lists/{$otherList->id}");
        $deleteResponse->assertNotFound();
    }

    public function test_a_duplicate_name_on_create_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        MailList::factory()->for($user)->create(['name' => 'Customers']);

        $response = $this->postJson('/api/v1/lists', ['name' => 'Customers']);

        $response->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame(1, MailList::query()->where('name', 'Customers')->count());
    }

    public function test_the_name_of_a_list_of_another_client_is_free(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        MailList::factory()->for(User::factory()->create())->create(['name' => 'Customers']);

        $this->postJson('/api/v1/lists', ['name' => 'Customers'])->assertCreated();
    }

    public function test_a_rename_to_the_name_of_another_list_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        MailList::factory()->for($user)->create(['name' => 'Customers']);
        $list = MailList::factory()->for($user)->create(['name' => 'Leads']);

        $response = $this->patchJson("/api/v1/lists/{$list->id}", ['name' => 'Customers']);

        $response->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('Leads', $list->fresh()->name);
    }

    public function test_a_list_keeps_its_own_name_on_update(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $list = MailList::factory()->for($user)->create(['name' => 'Leads']);

        $this->patchJson("/api/v1/lists/{$list->id}", ['name' => 'Leads'])->assertOk();
    }
}
