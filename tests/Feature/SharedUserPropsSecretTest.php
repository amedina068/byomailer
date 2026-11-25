<?php

namespace Tests\Feature;

use App\Models\EmailCredential;
use App\Models\User;
use App\Models\UserStorageSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The shared `auth.user` prop is in the HTML of every page. It must carry
 * only the user fields that the front end reads, and never a loaded relation.
 */
class SharedUserPropsSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_user_prop_has_only_the_listed_fields(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create(['quota_fetched_at' => now()]);

        $response = $this->actingAs($user)->get('/settings/identities');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('auth.user', fn (Assert $authUser) => $authUser
                ->where('id', $user->id)
                ->where('uuid', $user->uuid)
                ->where('name', $user->name)
                ->where('email', $user->email)
                ->where('role', $user->role->value)
                ->hasAll(['email_verified_at', 'invite_expires_at', 'archived_at', 'created_at', 'updated_at'])
                ->missing('ses_credential')
                ->missing('client_id')
            )
        );
        $this->assertStringNotContainsString($credential->secret_key, $response->getContent());
        $this->assertStringNotContainsString($credential->access_key, $response->getContent());
    }

    public function test_email_credential_never_serializes_its_aws_keys(): void
    {
        $credential = EmailCredential::factory()->create();

        $serialized = $credential->toArray();

        $this->assertArrayNotHasKey('access_key', $serialized);
        $this->assertArrayNotHasKey('secret_key', $serialized);
    }

    public function test_storage_setting_never_serializes_its_keys(): void
    {
        $setting = UserStorageSetting::factory()->create();

        $serialized = $setting->toArray();

        $this->assertArrayNotHasKey('access_key_id', $serialized);
        $this->assertArrayNotHasKey('secret_access_key', $serialized);
    }
}
