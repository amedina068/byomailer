<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_tokens_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('api-tokens.index'));

        $response->assertOk();
    }

    public function test_api_tokens_page_requires_authentication(): void
    {
        $response = $this->get(route('api-tokens.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_create_api_token(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('api-tokens.store'), [
                'name' => 'Test Token',
                'access' => 'read_write',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/ApiTokens')
                ->has('newToken')
                ->where('newToken', fn ($token) => str_contains($token, '|'))
            );

        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'Test Token',
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_api_token_name_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('api-tokens.index'))
            ->post(route('api-tokens.store'), [
                'name' => '',
                'access' => 'read',
            ]);

        $response
            ->assertSessionHasErrors('name')
            ->assertRedirect(route('api-tokens.index'));
    }

    public function test_user_can_delete_api_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Test Token');

        $response = $this
            ->actingAs($user)
            ->delete(route('api-tokens.destroy', $token->accessToken->id));

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('api-tokens.index'));

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function test_user_cannot_delete_another_users_token(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $otherUser->createToken('Test Token');

        $response = $this
            ->actingAs($user)
            ->delete(route('api-tokens.destroy', $token->accessToken->id));

        $response->assertRedirect(route('api-tokens.index'));

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function test_postman_collection_can_be_downloaded(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('documentation.postman'));

        $response
            ->assertOk()
            ->assertDownload('byomailer-postman-collection.json');

        $content = json_decode($response->streamedContent(), true);
        $this->assertArrayHasKey('info', $content);
        $this->assertArrayHasKey('auth', $content);
        $this->assertArrayHasKey('item', $content);
    }

    public function test_existing_tokens_are_displayed(): void
    {
        $user = User::factory()->create();
        $user->createToken('Token One');
        $user->createToken('Token Two');

        $response = $this
            ->actingAs($user)
            ->get(route('api-tokens.index'));

        $response
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/ApiTokens')
                ->has('tokens', 2)
                ->where('tokens.0.name', 'Token One')
                ->where('tokens.1.name', 'Token Two')
            );
    }

    public function test_read_only_token_gets_only_the_read_ability(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('api-tokens.store'), ['name' => 'Reader', 'access' => 'read'])
            ->assertOk();

        $this->assertSame(['read'], $user->tokens()->sole()->abilities);
    }

    public function test_read_write_token_gets_the_read_and_write_abilities(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('api-tokens.store'), ['name' => 'Writer', 'access' => 'read_write'])
            ->assertOk();

        $this->assertSame(['read', 'write'], $user->tokens()->sole()->abilities);
    }

    public function test_token_access_is_required_and_must_be_known(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('api-tokens.index'))
            ->post(route('api-tokens.store'), ['name' => 'No access'])
            ->assertSessionHasErrors(['access' => 'The access field is required.']);

        $this->actingAs($user)
            ->from(route('api-tokens.index'))
            ->post(route('api-tokens.store'), ['name' => 'Star', 'access' => '*'])
            ->assertSessionHasErrors(['access' => 'The selected access is invalid.']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_token_can_have_an_expiration(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('api-tokens.store'), ['name' => 'Short', 'access' => 'read', 'expires_in_days' => 30])
            ->assertOk();

        $this->assertTrue($user->tokens()->sole()->expires_at->equalTo(now()->addDays(30)));
    }

    public function test_token_without_an_expiration_does_not_expire(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('api-tokens.store'), ['name' => 'Forever', 'access' => 'read', 'expires_in_days' => null])
            ->assertOk();

        $this->assertNull($user->tokens()->sole()->expires_at);
    }

    public function test_token_expiration_must_be_a_listed_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('api-tokens.index'))
            ->post(route('api-tokens.store'), ['name' => 'Odd', 'access' => 'read', 'expires_in_days' => 7])
            ->assertSessionHasErrors(['expires_in_days' => 'The selected expires in days is invalid.']);
    }

    public function test_expired_token_is_refused_by_the_api(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Old', ['read'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/tags')->assertUnauthorized();
    }

    public function test_token_list_shows_the_access_and_the_expiration(): void
    {
        $user = User::factory()->create();
        $user->createToken('Legacy');
        $user->createToken('Reader', ['read'], now()->addDays(30));

        $this->actingAs($user)
            ->get(route('api-tokens.index'))
            ->assertInertia(fn ($page) => $page
                ->where('tokens.0.access', 'read_write')
                ->where('tokens.0.expires_at', null)
                ->where('tokens.1.access', 'read')
                ->where('tokens.1.expires_at', fn ($value) => is_string($value))
            );
    }
}
