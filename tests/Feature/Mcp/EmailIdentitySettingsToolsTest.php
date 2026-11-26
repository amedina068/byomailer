<?php

namespace Tests\Feature\Mcp;

use App\Exceptions\CloudflareException;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CheckEmailIdentityLinkDomainTool;
use App\Mcp\Tools\UpdateEmailIdentityLinkDomainTool;
use App\Mcp\Tools\UpdateEmailIdentityReplyToTool;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\CloudflareClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class EmailIdentitySettingsToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.cloudflare.cname_target' => 'links.byomailer.test']);
    }

    public function test_update_reply_to_sets_the_trimmed_email_and_name(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityReplyToTool::class, [
            'email_identity_id' => $identity->id,
            'reply_to_email' => ' support@example.com ',
            'reply_to_name' => ' Support Team ',
        ])->assertOk()
            ->assertSee('"reply_to_email":"support@example.com"')
            ->assertSee('"reply_to_name":"Support Team"')
            ->assertSee("Reply-To set to support@example.com for identity #{$identity->id}.");

        $identity = $identity->fresh();
        $this->assertSame('support@example.com', $identity->reply_to_email);
        $this->assertSame('Support Team', $identity->reply_to_name);
    }

    public function test_update_reply_to_clears_the_email_and_the_name(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create([
            'reply_to_email' => 'support@example.com',
            'reply_to_name' => 'Support Team',
        ]);

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityReplyToTool::class, [
            'email_identity_id' => $identity->id,
            'reply_to_email' => null,
        ])->assertOk()->assertSee("Reply-To cleared for identity #{$identity->id}.");

        $identity = $identity->fresh();
        $this->assertNull($identity->reply_to_email);
        $this->assertNull($identity->reply_to_name);
    }

    public function test_update_reply_to_lets_a_teammate_change_the_identity(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        $identity = EmailIdentity::factory()->for($owner)->create();

        ByoMailerServer::actingAs($teammate)->tool(UpdateEmailIdentityReplyToTool::class, [
            'email_identity_id' => $identity->id,
            'reply_to_email' => 'team@example.com',
        ])->assertOk();

        $this->assertSame('team@example.com', $identity->fresh()->reply_to_email);
    }

    public function test_update_reply_to_rejects_a_name_without_an_email(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create(['reply_to_email' => 'keep@example.com']);

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityReplyToTool::class, [
            'email_identity_id' => $identity->id,
            'reply_to_email' => '',
            'reply_to_name' => 'Support Team',
        ])->assertHasErrors(['Add an email address to use a Reply-To name.']);

        $this->assertSame('keep@example.com', $identity->fresh()->reply_to_email);
    }

    public function test_update_reply_to_rejects_a_line_break_in_the_name(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityReplyToTool::class, [
            'email_identity_id' => $identity->id,
            'reply_to_email' => 'support@example.com',
            'reply_to_name' => "Support\r\nBcc: victim@example.com",
        ])->assertHasErrors(['The name cannot contain line breaks.']);

        $this->assertNull($identity->fresh()->reply_to_email);
    }

    public function test_update_reply_to_requires_the_email_argument(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create(['reply_to_email' => 'keep@example.com']);

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityReplyToTool::class, [
            'email_identity_id' => $identity->id,
        ])->assertHasErrors(['The reply to email field must be present.']);

        $this->assertSame('keep@example.com', $identity->fresh()->reply_to_email);
    }

    public function test_update_reply_to_errors_for_another_clients_identity(): void
    {
        $user = User::factory()->create();
        $foreign = EmailIdentity::factory()->create(['reply_to_email' => 'theirs@example.com']);

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityReplyToTool::class, [
            'email_identity_id' => $foreign->id,
            'reply_to_email' => 'hijack@example.com',
        ])->assertHasErrors(["No email identity found with id {$foreign->id}."]);

        $this->assertSame('theirs@example.com', $foreign->fresh()->reply_to_email);
    }

    public function test_update_link_domain_registers_the_lowercase_domain_and_returns_the_cname_record(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $this->fakeCloudflare()
            ->shouldReceive('createCustomHostname')
            ->once()
            ->with('links.example.com')
            ->andReturn($this->hostname('cf-1', 'links.example.com'));

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityLinkDomainTool::class, [
            'email_identity_id' => $identity->id,
            'custom_link_domain' => ' Links.Example.com ',
        ])->assertOk()
            ->assertSee('"custom_link_domain":"links.example.com"')
            ->assertSee('"custom_link_domain_verified":false')
            ->assertSee('"type":"CNAME"')
            ->assertSee('"value":"links.byomailer.test"')
            ->assertSee('"purpose":"link_domain"')
            ->assertSee('check-email-identity-link-domain-tool');

        $identity = $identity->fresh();
        $this->assertSame('links.example.com', $identity->custom_link_domain);
        $this->assertSame('cf-1', $identity->cf_custom_hostname_id);
        $this->assertNull($identity->custom_link_domain_verified_at);
    }

    public function test_update_link_domain_replaces_the_old_cloudflare_hostname(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('old.example.com')->create(['cf_custom_hostname_id' => 'cf-old']);

        $cloudflare = $this->fakeCloudflare();
        $cloudflare->shouldReceive('createCustomHostname')->once()->with('new.example.com')
            ->andReturn($this->hostname('cf-new', 'new.example.com'));
        $cloudflare->shouldReceive('deleteCustomHostname')->once()->with('cf-old');

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityLinkDomainTool::class, [
            'email_identity_id' => $identity->id,
            'custom_link_domain' => 'new.example.com',
        ])->assertOk();

        $identity = $identity->fresh();
        $this->assertSame('new.example.com', $identity->custom_link_domain);
        $this->assertSame('cf-new', $identity->cf_custom_hostname_id);
        $this->assertNull($identity->custom_link_domain_verified_at);
    }

    public function test_update_link_domain_clears_the_domain(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('links.example.com')->create(['cf_custom_hostname_id' => 'cf-1']);

        $cloudflare = $this->fakeCloudflare();
        $cloudflare->shouldNotReceive('createCustomHostname');
        $cloudflare->shouldReceive('deleteCustomHostname')->once()->with('cf-1');

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityLinkDomainTool::class, [
            'email_identity_id' => $identity->id,
            'custom_link_domain' => null,
        ])->assertOk()
            ->assertSee('"custom_link_domain":null')
            ->assertSee('"dns_records":[]')
            ->assertSee("Custom link domain removed from identity #{$identity->id}.");

        $identity = $identity->fresh();
        $this->assertNull($identity->custom_link_domain);
        $this->assertNull($identity->cf_custom_hostname_id);
        $this->assertNull($identity->custom_link_domain_verified_at);
    }

    public function test_update_link_domain_errors_when_cloudflare_is_not_configured(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create(['custom_link_domain' => null]);

        $this->fakeCloudflare(configured: false)->shouldNotReceive('createCustomHostname');

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityLinkDomainTool::class, [
            'email_identity_id' => $identity->id,
            'custom_link_domain' => 'links.example.com',
        ])->assertHasErrors(['Custom link domains are not available on this BYOMailer server.']);

        $this->assertNull($identity->fresh()->custom_link_domain);
    }

    public function test_update_link_domain_keeps_the_old_domain_when_cloudflare_fails(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('old.example.com')->create(['cf_custom_hostname_id' => 'cf-old']);

        $cloudflare = $this->fakeCloudflare();
        $cloudflare->shouldReceive('createCustomHostname')->once()
            ->andThrow(new CloudflareException('Hostname is a duplicate.'));
        $cloudflare->shouldNotReceive('deleteCustomHostname');

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityLinkDomainTool::class, [
            'email_identity_id' => $identity->id,
            'custom_link_domain' => 'new.example.com',
        ])->assertHasErrors(['Cloudflare did not register the link domain: Hostname is a duplicate.']);

        $identity = $identity->fresh();
        $this->assertSame('old.example.com', $identity->custom_link_domain);
        $this->assertSame('cf-old', $identity->cf_custom_hostname_id);
    }

    public function test_update_link_domain_rejects_the_domain_of_another_client(): void
    {
        EmailIdentity::factory()->withLinkDomain('links.example.com')->create();
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create(['custom_link_domain' => null]);

        $this->fakeCloudflare()->shouldNotReceive('createCustomHostname');

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityLinkDomainTool::class, [
            'email_identity_id' => $identity->id,
            'custom_link_domain' => 'LINKS.example.com',
        ])->assertHasErrors(['This link domain is already in use by another account.']);

        $this->assertNull($identity->fresh()->custom_link_domain);
    }

    public function test_update_link_domain_rejects_an_invalid_hostname(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create(['custom_link_domain' => null]);

        $this->fakeCloudflare()->shouldNotReceive('createCustomHostname');

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityLinkDomainTool::class, [
            'email_identity_id' => $identity->id,
            'custom_link_domain' => 'not a domain',
        ])->assertHasErrors(['The link domain must be a valid hostname']);

        $this->assertNull($identity->fresh()->custom_link_domain);
    }

    public function test_update_link_domain_errors_for_another_clients_identity(): void
    {
        $user = User::factory()->create();
        $foreign = EmailIdentity::factory()->withLinkDomain('theirs.example.com')->create();

        $cloudflare = $this->fakeCloudflare();
        $cloudflare->shouldNotReceive('createCustomHostname');
        $cloudflare->shouldNotReceive('deleteCustomHostname');

        ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityLinkDomainTool::class, [
            'email_identity_id' => $foreign->id,
            'custom_link_domain' => null,
        ])->assertHasErrors(["No email identity found with id {$foreign->id}."]);

        $this->assertSame('theirs.example.com', $foreign->fresh()->custom_link_domain);
    }

    public function test_check_link_domain_reports_a_pending_domain_with_its_cname_record(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('links.example.com', verified: false)->create(['cf_custom_hostname_id' => 'cf-1']);

        $this->fakeCloudflare()->shouldReceive('getCustomHostname')->once()->with('cf-1')
            ->andReturn($this->hostname('cf-1', 'links.example.com'));

        ByoMailerServer::actingAs($user)->tool(CheckEmailIdentityLinkDomainTool::class, [
            'email_identity_id' => $identity->id,
        ])->assertOk()
            ->assertSee('"custom_link_domain_verified":false')
            ->assertSee('"name":"links.example.com"')
            ->assertSee('"value":"links.byomailer.test"')
            ->assertSee('links.example.com is not verified yet.');

        $this->assertNull($identity->fresh()->custom_link_domain_verified_at);
    }

    public function test_check_link_domain_verifies_an_active_domain(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('links.example.com', verified: false)->create(['cf_custom_hostname_id' => 'cf-1']);

        $this->fakeCloudflare()->shouldReceive('getCustomHostname')->once()->with('cf-1')
            ->andReturn($this->hostname('cf-1', 'links.example.com', 'active'));

        ByoMailerServer::actingAs($user)->tool(CheckEmailIdentityLinkDomainTool::class, [
            'email_identity_id' => $identity->id,
        ])->assertOk()
            ->assertSee('"custom_link_domain_verified":true')
            ->assertSee('"verified":true')
            ->assertSee('links.example.com is verified.');

        $this->assertNotNull($identity->fresh()->custom_link_domain_verified_at);
    }

    public function test_check_link_domain_surfaces_a_cloudflare_error(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)
            ->withLinkDomain('links.example.com', verified: false)->create(['cf_custom_hostname_id' => 'cf-1']);

        $this->fakeCloudflare()->shouldReceive('getCustomHostname')->once()
            ->andThrow(new CloudflareException('Cloudflare is down.'));

        ByoMailerServer::actingAs($user)->tool(CheckEmailIdentityLinkDomainTool::class, [
            'email_identity_id' => $identity->id,
        ])->assertHasErrors(['The check of links.example.com failed: Cloudflare is down.']);

        $this->assertNull($identity->fresh()->custom_link_domain_verified_at);
    }

    public function test_check_link_domain_errors_without_a_link_domain(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create(['custom_link_domain' => null]);

        $this->fakeCloudflare()->shouldNotReceive('getCustomHostname');

        ByoMailerServer::actingAs($user)->tool(CheckEmailIdentityLinkDomainTool::class, [
            'email_identity_id' => $identity->id,
        ])->assertHasErrors(["Identity #{$identity->id} has no custom link domain."]);
    }

    public function test_check_link_domain_errors_for_another_clients_identity(): void
    {
        $user = User::factory()->create();
        $foreign = EmailIdentity::factory()
            ->withLinkDomain('theirs.example.com', verified: false)->create(['cf_custom_hostname_id' => 'cf-9']);

        $this->fakeCloudflare()->shouldNotReceive('getCustomHostname');

        ByoMailerServer::actingAs($user)->tool(CheckEmailIdentityLinkDomainTool::class, [
            'email_identity_id' => $foreign->id,
        ])->assertHasErrors(["No email identity found with id {$foreign->id}."]);

        $this->assertNull($foreign->fresh()->custom_link_domain_verified_at);
    }

    private function fakeCloudflare(bool $configured = true): MockInterface
    {
        $mock = Mockery::mock(CloudflareClient::class);
        $mock->shouldReceive('isConfigured')->andReturn($configured);
        $this->app->instance(CloudflareClient::class, $mock);

        return $mock;
    }

    /**
     * @return array{id: string, hostname: string, status: string, ssl_status: string}
     */
    private function hostname(string $id, string $hostname, string $status = 'pending'): array
    {
        return [
            'id' => $id,
            'hostname' => $hostname,
            'status' => $status,
            'ssl_status' => $status === 'active' ? 'active' : 'pending_validation',
        ];
    }
}
