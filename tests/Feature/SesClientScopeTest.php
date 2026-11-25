<?php

namespace Tests\Feature;

use App\Models\EmailCredential;
use App\Models\User;
use App\Services\SesBulkEmailService;
use App\Services\SesClientManager;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The queue worker calls `forgetScopedInstances()` before each job. The SES
 * services must be scoped, so that a long-lived worker builds new clients
 * after a key change and does not keep the old secret in memory.
 */
class SesClientScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_ses_services_are_shared_inside_one_scope(): void
    {
        $this->assertSame(app(SesClientManager::class), app(SesClientManager::class));
        $this->assertSame(app(SesBulkEmailService::class), app(SesBulkEmailService::class));
    }

    public function test_a_new_scope_builds_the_client_with_the_new_keys(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAOLDKEY0000000001',
            'region' => 'us-east-1',
        ]);

        $oldClient = Tenant::run($user->client_id, fn () => app(SesClientManager::class)->clientFor($user));
        $oldBulkService = app(SesBulkEmailService::class);

        $credential->update(['access_key' => 'AKIANEWKEY0000000001', 'region' => 'eu-west-1']);
        $user->unsetRelation('sesCredential');
        app()->forgetScopedInstances();

        $newClient = Tenant::run($user->client_id, fn () => app(SesClientManager::class)->clientFor($user));

        $this->assertSame('AKIAOLDKEY0000000001', $oldClient->getCredentials()->wait()->getAccessKeyId());
        $this->assertSame('AKIANEWKEY0000000001', $newClient->getCredentials()->wait()->getAccessKeyId());
        $this->assertSame('eu-west-1', $newClient->getRegion());
        $this->assertNotSame($oldBulkService, app(SesBulkEmailService::class));
    }
}
