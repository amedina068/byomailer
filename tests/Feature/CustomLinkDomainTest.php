<?php

namespace Tests\Feature;

use App\Exceptions\CloudflareException;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\CloudflareClient;
use App\Services\LinkDomainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CustomLinkDomainTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCloudflare(bool $configured = true): MockInterface
    {
        $mock = Mockery::mock(CloudflareClient::class);
        $mock->shouldReceive('isConfigured')->andReturn($configured);
        $this->app->instance(CloudflareClient::class, $mock);

        return $mock;
    }

    public function test_user_can_save_custom_link_domain(): void
    {
        config(['services.cloudflare.cname_target' => 'link.byomailer.com']);
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $this->fakeCloudflare()
            ->shouldReceive('createCustomHostname')
            ->once()
            ->with('mail.example.com')
            ->andReturn(['id' => 'cf-1', 'hostname' => 'mail.example.com', 'status' => 'pending', 'ssl_status' => 'pending_validation']);

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-link-domain', $identity),
            ['enabled' => true, 'custom_link_domain' => 'mail.example.com']
        );

        $response->assertOk();
        $response->assertJson(['success' => true, 'cname_target' => 'link.byomailer.com']);

        $identity->refresh();
        $this->assertSame('mail.example.com', $identity->custom_link_domain);
        $this->assertSame('cf-1', $identity->cf_custom_hostname_id);
        $this->assertNull($identity->custom_link_domain_verified_at);
    }

    public function test_save_returns_503_when_cloudflare_not_configured(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $this->fakeCloudflare(configured: false);

        $this->actingAs($user)->putJson(
            route('identities.update-custom-link-domain', $identity),
            ['enabled' => true, 'custom_link_domain' => 'mail.example.com']
        )->assertStatus(503);
    }

    public function test_user_can_remove_custom_link_domain(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('mail.example.com')->create(['cf_custom_hostname_id' => 'cf-1']);

        $this->fakeCloudflare()
            ->shouldReceive('deleteCustomHostname')->once()->with('cf-1');

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-link-domain', $identity),
            ['enabled' => false]
        );

        $response->assertOk();
        $identity->refresh();
        $this->assertNull($identity->custom_link_domain);
        $this->assertNull($identity->cf_custom_hostname_id);
        $this->assertNull($identity->custom_link_domain_verified_at);
    }

    public function test_changing_domain_deletes_old_cloudflare_hostname(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('old.example.com')->create(['cf_custom_hostname_id' => 'cf-old']);

        $cf = $this->fakeCloudflare();
        $cf->shouldReceive('deleteCustomHostname')->once()->with('cf-old');
        $cf->shouldReceive('createCustomHostname')->once()->with('new.example.com')
            ->andReturn(['id' => 'cf-new', 'hostname' => 'new.example.com', 'status' => 'pending', 'ssl_status' => 'pending_validation']);

        $this->actingAs($user)->putJson(
            route('identities.update-custom-link-domain', $identity),
            ['enabled' => true, 'custom_link_domain' => 'new.example.com']
        )->assertOk();

        $identity->refresh();
        $this->assertSame('new.example.com', $identity->custom_link_domain);
        $this->assertSame('cf-new', $identity->cf_custom_hostname_id);
        $this->assertNull($identity->custom_link_domain_verified_at);
    }

    public function test_resaving_same_domain_does_not_call_cloudflare(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('mail.example.com')->create(['cf_custom_hostname_id' => 'cf-1']);

        $cf = $this->fakeCloudflare();
        $cf->shouldNotReceive('createCustomHostname');
        $cf->shouldNotReceive('deleteCustomHostname');

        $this->actingAs($user)->putJson(
            route('identities.update-custom-link-domain', $identity),
            ['enabled' => true, 'custom_link_domain' => 'mail.example.com']
        )->assertOk();

        $this->assertNotNull($identity->fresh()->custom_link_domain_verified_at);
    }

    public function test_cloudflare_failure_returns_422(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $this->fakeCloudflare()
            ->shouldReceive('createCustomHostname')
            ->andThrow(new CloudflareException('hostname already exists'));

        $response = $this->actingAs($user)->putJson(
            route('identities.update-custom-link-domain', $identity),
            ['enabled' => true, 'custom_link_domain' => 'mail.example.com']
        );

        $response->assertUnprocessable();
        $response->assertJsonPath('error', 'Failed to register link domain with Cloudflare: hostname already exists');
    }

    public function test_create_failure_preserves_existing_link_domain(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('old.example.com')->create(['cf_custom_hostname_id' => 'cf-old']);

        $cf = $this->fakeCloudflare();
        $cf->shouldReceive('createCustomHostname')->andThrow(new CloudflareException('boom'));
        $cf->shouldNotReceive('deleteCustomHostname');

        $this->actingAs($user)->putJson(
            route('identities.update-custom-link-domain', $identity),
            ['enabled' => true, 'custom_link_domain' => 'new.example.com']
        )->assertUnprocessable();

        $identity->refresh();
        $this->assertSame('old.example.com', $identity->custom_link_domain);
        $this->assertSame('cf-old', $identity->cf_custom_hostname_id);
        $this->assertNotNull($identity->custom_link_domain_verified_at);
    }

    public function test_remove_clears_state_even_when_cloudflare_delete_fails(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('mail.example.com')->create(['cf_custom_hostname_id' => 'cf-1']);

        $this->fakeCloudflare()
            ->shouldReceive('deleteCustomHostname')->andThrow(new CloudflareException('cf down'));

        $this->actingAs($user)->putJson(
            route('identities.update-custom-link-domain', $identity),
            ['enabled' => false]
        )->assertOk();

        $identity->refresh();
        $this->assertNull($identity->custom_link_domain);
        $this->assertNull($identity->cf_custom_hostname_id);
        $this->assertNull($identity->custom_link_domain_verified_at);
    }

    public function test_domain_is_lowercased_on_save(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $this->fakeCloudflare()
            ->shouldReceive('createCustomHostname')->once()->with('mail.example.com')
            ->andReturn(['id' => 'cf-1', 'hostname' => 'mail.example.com', 'status' => 'pending', 'ssl_status' => 'pending_validation']);

        $this->actingAs($user)->putJson(
            route('identities.update-custom-link-domain', $identity),
            ['enabled' => true, 'custom_link_domain' => 'Mail.Example.COM']
        )->assertOk();

        $this->assertSame('mail.example.com', $identity->fresh()->custom_link_domain);
    }

    public function test_destroying_identity_deletes_cloudflare_hostname(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('mail.example.com')->create(['cf_custom_hostname_id' => 'cf-1']);

        $this->fakeCloudflare()
            ->shouldReceive('deleteCustomHostname')->once()->with('cf-1');

        $this->actingAs($user)->delete(route('identities.destroy', $identity))
            ->assertRedirect(route('identities.index'));

        $this->assertDatabaseMissing('email_identities', ['id' => $identity->id]);
    }

    public function test_validation_rejects_invalid_domain(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $this->fakeCloudflare();

        $this->actingAs($user)->putJson(
            route('identities.update-custom-link-domain', $identity),
            ['enabled' => true, 'custom_link_domain' => 'not a domain!']
        )->assertUnprocessable()->assertJsonValidationErrors(['custom_link_domain']);
    }

    public function test_validation_requires_domain_when_enabled(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $this->fakeCloudflare();

        $this->actingAs($user)->putJson(
            route('identities.update-custom-link-domain', $identity),
            ['enabled' => true, 'custom_link_domain' => null]
        )->assertUnprocessable()->assertJsonValidationErrors(['custom_link_domain']);
    }

    public function test_check_returns_not_verified_when_hostname_pending(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('mail.example.com', verified: false)->create(['cf_custom_hostname_id' => 'cf-1']);

        $client = Mockery::mock(CloudflareClient::class);
        $client->shouldReceive('getCustomHostname')->andReturn(
            ['id' => 'cf-1', 'hostname' => 'mail.example.com', 'status' => 'pending', 'ssl_status' => 'pending_validation']
        );
        $this->app->instance(LinkDomainVerifier::class, new LinkDomainVerifier($client));

        $response = $this->actingAs($user)->postJson(
            route('identities.check-custom-link-domain', $identity)
        );

        $response->assertOk();
        $response->assertJson(['verified' => false]);
        $response->assertJsonStructure(['verified', 'checked_at']);
    }

    public function test_check_verifies_when_hostname_active(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('mail.example.com', verified: false)->create(['cf_custom_hostname_id' => 'cf-1']);

        $client = Mockery::mock(CloudflareClient::class);
        $client->shouldReceive('getCustomHostname')->andReturn(
            ['id' => 'cf-1', 'hostname' => 'mail.example.com', 'status' => 'active', 'ssl_status' => 'active']
        );
        $this->app->instance(LinkDomainVerifier::class, new LinkDomainVerifier($client));

        $response = $this->actingAs($user)->postJson(
            route('identities.check-custom-link-domain', $identity)
        );

        $response->assertOk();
        $response->assertJson(['verified' => true]);
        $this->assertNotNull($identity->fresh()->custom_link_domain_verified_at);
    }

    public function test_check_returns_cached_result_within_cooldown(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('mail.example.com', verified: false)->create();

        $cached = ['verified' => false, 'checked_at' => now()->subSeconds(30)->toIso8601String()];
        Cache::put("link-domain-check:{$identity->id}", $cached, 300);

        $this->actingAs($user)->postJson(route('identities.check-custom-link-domain', $identity))
            ->assertOk()->assertJson($cached);
    }

    public function test_check_pending_result_uses_short_cooldown_so_re_check_can_activate(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('mail.example.com', verified: false)->create(['cf_custom_hostname_id' => 'cf-1']);

        $client = Mockery::mock(CloudflareClient::class);
        $client->shouldReceive('getCustomHostname')->andReturn(
            ['id' => 'cf-1', 'hostname' => 'mail.example.com', 'status' => 'pending', 'ssl_status' => 'pending_validation'],
            ['id' => 'cf-1', 'hostname' => 'mail.example.com', 'status' => 'active', 'ssl_status' => 'active'],
        );
        $this->app->instance(LinkDomainVerifier::class, new LinkDomainVerifier($client));

        $this->actingAs($user)->postJson(route('identities.check-custom-link-domain', $identity))
            ->assertOk()->assertJson(['verified' => false]);

        $this->travel(31)->seconds();

        $this->actingAs($user)->postJson(route('identities.check-custom-link-domain', $identity))
            ->assertOk()->assertJson(['verified' => true]);

        $this->assertNotNull($identity->fresh()->custom_link_domain_verified_at);
    }

    public function test_check_returns_404_when_no_link_domain(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create(['custom_link_domain' => null]);

        $this->actingAs($user)->postJson(route('identities.check-custom-link-domain', $identity))
            ->assertNotFound();
    }

    public function test_guest_cannot_access_endpoints(): void
    {
        $this->putJson(route('identities.update-custom-link-domain', 999), [
            'enabled' => true, 'custom_link_domain' => 'mail.example.com',
        ])->assertUnauthorized();

        $this->postJson(route('identities.check-custom-link-domain', 999))->assertUnauthorized();
    }

    public function test_user_cannot_access_other_clients_identity(): void
    {
        $user = User::factory()->create();
        $otherIdentity = EmailIdentity::factory()->for(User::factory()->create())
            ->withLinkDomain('mail.example.com')->create();
        $this->fakeCloudflare();

        $this->actingAs($user)->putJson(
            route('identities.update-custom-link-domain', $otherIdentity),
            ['enabled' => true, 'custom_link_domain' => 'mail.example.com']
        )->assertNotFound();

        $this->actingAs($user)->postJson(
            route('identities.check-custom-link-domain', $otherIdentity)
        )->assertNotFound();
    }

    public function test_user_cannot_claim_the_link_domain_of_another_client(): void
    {
        $owner = User::factory()->create();
        EmailIdentity::factory()->for($owner)->withLinkDomain('mail.example.com')->create();

        $attacker = User::factory()->create();
        $identity = EmailIdentity::factory()->for($attacker)->create();
        $this->fakeCloudflare()->shouldNotReceive('createCustomHostname');

        $this->actingAs($attacker)->putJson(
            route('identities.update-custom-link-domain', $identity),
            ['enabled' => true, 'custom_link_domain' => 'Mail.Example.com ']
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['custom_link_domain' => 'This link domain is already in use by another account.']);

        $this->assertNull($identity->fresh()->custom_link_domain);
    }

    public function test_client_can_use_its_own_link_domain_on_a_second_identity(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->withLinkDomain('mail.example.com')->create();
        $second = EmailIdentity::factory()->for($user)->create();

        $this->fakeCloudflare()
            ->shouldReceive('createCustomHostname')
            ->once()
            ->andReturn(['id' => 'cf-2', 'hostname' => 'mail.example.com', 'status' => 'pending', 'ssl_status' => 'pending_validation']);

        $this->actingAs($user)->putJson(
            route('identities.update-custom-link-domain', $second),
            ['enabled' => true, 'custom_link_domain' => 'mail.example.com']
        )->assertOk();
    }
}
