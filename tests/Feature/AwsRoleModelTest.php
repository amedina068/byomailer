<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\EmailCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AwsRoleModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_gets_one_random_external_id_that_does_not_change(): void
    {
        $client = Client::factory()->create();

        $externalId = $client->awsExternalId();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $externalId);
        $this->assertSame($externalId, $client->awsExternalId());
        $this->assertSame($externalId, $client->fresh()->awsExternalId());
    }

    public function test_each_client_gets_a_different_external_id(): void
    {
        $first = Client::factory()->create();
        $second = Client::factory()->create();

        $this->assertNotSame($first->awsExternalId(), $second->awsExternalId());
    }

    public function test_a_stale_client_instance_keeps_the_external_id_that_was_saved_first(): void
    {
        $client = Client::factory()->create();
        $stale = Client::query()->find($client->id);

        $externalId = $client->awsExternalId();

        $this->assertSame($externalId, $stale->awsExternalId());
    }

    public function test_the_external_id_is_hidden_from_arrays(): void
    {
        $client = Client::factory()->create();
        $client->awsExternalId();

        $this->assertArrayNotHasKey('aws_external_id', $client->toArray());
    }

    public function test_a_credential_uses_a_role_only_when_it_has_a_role_arn(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        $keys = EmailCredential::factory()->for($user)->make();
        $role = EmailCredential::factory()->for($user)->role()->make();

        $this->assertFalse($keys->usesRole());
        $this->assertTrue($role->usesRole());
        $this->assertNull($role->access_key);
        $this->assertNull($role->secret_key);
    }

    public function test_a_role_credential_saves_without_access_keys(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        $credential = EmailCredential::factory()->for($user)->role()->create();

        $this->assertNull($credential->fresh()->access_key);
        $this->assertStringStartsWith('arn:aws:iam::', $credential->fresh()->role_arn);
    }
}
