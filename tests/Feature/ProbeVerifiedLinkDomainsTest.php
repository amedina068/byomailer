<?php

namespace Tests\Feature;

use App\Exceptions\LinkDomainUnhealthyException;
use App\Models\EmailIdentity;
use App\Services\SsrfGuard;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProbeVerifiedLinkDomainsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $guard = new SsrfGuard;
        $guard->setDnsResolver(fn (string $host): string => '93.184.216.34');
        $this->app->instance(SsrfGuard::class, $guard);

        Exceptions::fake();
    }

    public function test_a_healthy_domain_reports_nothing(): void
    {
        Http::fake(['mail.healthy.example/up' => Http::response('Application up')]);

        EmailIdentity::factory()->withLinkDomain('mail.healthy.example')->create();

        $this->artisan('email-identities:probe-link-domains')
            ->expectsOutputToContain('mail.healthy.example: healthy.')
            ->assertSuccessful();

        Exceptions::assertNothingReported();
    }

    /**
     * A broken verified domain must alert, but it must stay verified. If the
     * app clears it, sent mail falls back to links on the app host.
     */
    public function test_an_unhealthy_domain_is_reported_and_stays_verified(): void
    {
        Http::fake([
            'mail.healthy.example/*' => Http::response('Application up'),
            'mail.broken.example/*' => Http::response('', 301, ['Location' => 'https://byomailer.example/up']),
        ]);

        $verifiedAt = Carbon::parse('2026-08-01 10:00:00');
        EmailIdentity::factory()->withLinkDomain('mail.healthy.example')->create();
        $broken = EmailIdentity::factory()->withLinkDomain('mail.broken.example')->create([
            'custom_link_domain_verified_at' => $verifiedAt,
        ]);

        $this->artisan('email-identities:probe-link-domains')
            ->expectsOutputToContain('mail.broken.example: https://mail.broken.example/up redirects to https://byomailer.example/up.')
            ->assertFailed();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (LinkDomainUnhealthyException $e): bool => str_contains($e->getMessage(), 'mail.broken.example')
            && str_contains($e->getMessage(), "email identity {$broken->id}")
            && str_contains($e->getMessage(), 'redirects to https://byomailer.example/up'));

        $this->assertTrue($broken->fresh()->custom_link_domain_verified_at->equalTo($verifiedAt));
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://byomailer.example'));
    }

    public function test_a_timeout_is_reported_and_the_next_domain_is_still_probed(): void
    {
        Http::fake([
            'mail.slow.example/*' => Http::failedConnection('cURL error 28: Operation timed out'),
            'mail.healthy.example/*' => Http::response('Application up'),
        ]);

        $slow = EmailIdentity::factory()->withLinkDomain('mail.slow.example')->create();
        EmailIdentity::factory()->withLinkDomain('mail.healthy.example')->create();

        $this->artisan('email-identities:probe-link-domains')
            ->expectsOutputToContain('mail.healthy.example: healthy.')
            ->assertFailed();

        Exceptions::assertReported(fn (LinkDomainUnhealthyException $e): bool => str_contains($e->getMessage(), 'did not answer'));
        $this->assertNotNull($slow->fresh()->custom_link_domain_verified_at);
    }

    public function test_it_skips_unverified_domains(): void
    {
        Http::fake();

        EmailIdentity::factory()->withLinkDomain('mail.pending.example', verified: false)->create();

        $this->artisan('email-identities:probe-link-domains')
            ->expectsOutputToContain('No verified custom link domains to probe.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_runs_every_hour(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'email-identities:probe-link-domains'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
