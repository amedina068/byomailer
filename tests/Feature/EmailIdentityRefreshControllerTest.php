<?php

namespace Tests\Feature;

use App\Enums\IdentityStatus;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\SesDomainIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EmailIdentityRefreshControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_refresh_marks_identity_verified_and_returns_fresh_status(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'key',
            'secret_key' => 'secret',
            'region' => 'us-east-1',
        ]);

        $identity = EmailIdentity::factory()->for($user)->pending()->create([
            'domain' => 'example.com',
            'mail_from_subdomain' => 'mas.example.com',
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')
            ->with('example.com', 'key', 'secret', 'us-east-1', null)
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'verified',
                'dkim_status' => 'SUCCESS',
                'mail_from_status' => 'SUCCESS',
            ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)
            ->patchJson(route('identities.refresh', $identity));

        $response->assertOk()->assertJson([
            'success' => true,
            'identity_status' => IdentityStatus::Verified->value,
            'dkim_status' => 'SUCCESS',
            'mail_from_status' => 'SUCCESS',
        ]);

        $identity->refresh();
        $this->assertSame(IdentityStatus::Verified, $identity->identity_status);
        $this->assertNotNull($identity->verified_at);
        $this->assertNotNull($identity->last_status_check_at);
    }

    public function test_refresh_keeps_verifying_when_aws_still_pending(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $identity = EmailIdentity::factory()->for($user)->verifying()->create([
            'domain' => 'example.com',
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => true,
            'status' => 'pending',
            'dkim_status' => 'PENDING',
            'mail_from_status' => 'PENDING',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)
            ->patchJson(route('identities.refresh', $identity));

        $response->assertOk()->assertJson([
            'success' => true,
            'identity_status' => IdentityStatus::Verifying->value,
            'dkim_status' => 'PENDING',
            'mail_from_status' => 'PENDING',
        ]);

        $identity->refresh();
        $this->assertSame(IdentityStatus::Verifying, $identity->identity_status);
        $this->assertNull($identity->verified_at);
    }

    public function test_refresh_returns_422_when_no_credentials(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldNotReceive('getIdentityStatus');
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)
            ->patchJson(route('identities.refresh', $identity));

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'error' => 'No AWS credentials found.',
        ]);
    }

    public function test_refresh_returns_422_when_aws_fails(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->pending()->create();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => false,
            'error' => 'Access denied',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)
            ->patchJson(route('identities.refresh', $identity));

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'error' => 'Access denied',
        ]);

        $identity->refresh();
        $this->assertSame(IdentityStatus::Pending, $identity->identity_status);
    }

    public function test_refresh_returns_404_for_other_client_identity(): void
    {
        $owner = User::factory()->create();
        EmailCredential::factory()->for($owner)->create();
        $intruder = User::factory()->create();
        EmailCredential::factory()->for($intruder)->create();

        $identity = EmailIdentity::factory()->for($owner)->create();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldNotReceive('getIdentityStatus');
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($intruder)
            ->patchJson(route('identities.refresh', $identity));

        $response->assertNotFound();
    }

    public function test_refresh_requires_authentication(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $response = $this->patchJson(route('identities.refresh', $identity));

        $response->assertUnauthorized();
    }
}
