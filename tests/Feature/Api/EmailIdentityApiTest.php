<?php

namespace Tests\Feature\Api;

use App\Enums\IdentityStatus;
use App\Models\Client;
use App\Models\EmailIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_identities_can_be_listed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $otherIdentity = EmailIdentity::factory()->create();
        EmailIdentity::factory()->for($user)->create(['email' => 'hello@example.com']);

        $response = $this->getJson('/api/v1/email-identities');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'hello@example.com')
            ->assertJsonMissing(['email' => $otherIdentity->email]);
    }

    public function test_email_identities_include_dns_records(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        EmailIdentity::factory()->for($user)->create();

        $response = $this->getJson('/api/v1/email-identities');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'uuid',
                        'email',
                        'name',
                        'domain',
                        'identity_status',
                        'verified_at',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    public function test_email_identities_require_authentication(): void
    {
        $response = $this->getJson('/api/v1/email-identities');

        $response->assertUnauthorized();
    }

    public function test_email_identities_can_be_filtered_by_status(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        EmailIdentity::factory()->for($user)->create(['identity_status' => IdentityStatus::Verified, 'email' => 'ok@example.com']);
        EmailIdentity::factory()->for($user)->create(['identity_status' => IdentityStatus::Pending, 'email' => 'pending@example.com']);

        $response = $this->getJson('/api/v1/email-identities?identity_status=verified');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'ok@example.com');
    }

    public function test_user_can_show_single_identity(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $identity = EmailIdentity::factory()->for($user)->create();

        $response = $this->getJson("/api/v1/email-identities/{$identity->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $identity->id)
            ->assertJsonPath('data.email', $identity->email);
    }

    public function test_show_returns_404_for_other_client_identity(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $other = Client::factory()->create();
        $identity = EmailIdentity::factory()->for($other)->create();

        $response = $this->getJson("/api/v1/email-identities/{$identity->id}");

        $response->assertNotFound();
    }
}
