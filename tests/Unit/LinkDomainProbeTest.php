<?php

namespace Tests\Unit;

use App\Services\LinkDomainProbe;
use App\Services\SsrfGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkDomainProbeTest extends TestCase
{
    private function probe(string|false $resolvedIp = '93.184.216.34'): LinkDomainProbe
    {
        $guard = new SsrfGuard;
        $guard->setDnsResolver(fn (string $host): string|false => $resolvedIp);

        return new LinkDomainProbe($guard);
    }

    public function test_healthy_when_the_app_answers_200(): void
    {
        Http::fake(['mail.example.com/up' => Http::response('Application up')]);

        $result = $this->probe()->check('mail.example.com');

        $this->assertSame(['healthy' => true], $result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://mail.example.com/up'
            && $request->hasHeader('User-Agent', 'BYOMailer-LinkDomainProbe/1.0'));
    }

    /**
     * The customer controls the DNS of the domain. The request must have a
     * time limit, must not follow a redirect, and must connect to the address
     * that the guard checked.
     */
    public function test_the_request_has_a_time_limit_no_redirects_and_a_pinned_address(): void
    {
        $options = [];

        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response('Application up');
        });

        $this->probe()->check('mail.example.com');

        $this->assertSame(LinkDomainProbe::CONNECT_TIMEOUT_SECONDS, $options['connect_timeout']);
        $this->assertSame(LinkDomainProbe::TIMEOUT_SECONDS, $options['timeout']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(['mail.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
    }

    /**
     * The web server answers the link host with a 301 to the app host.
     * A probe that follows the redirect gets a 200 and hides the fault.
     */
    public function test_unhealthy_on_a_redirect_and_does_not_follow_it(): void
    {
        Http::fake([
            'mail.example.com/*' => Http::response('', 301, ['Location' => 'https://byomailer.example/up']),
            'byomailer.example/*' => Http::response('Application up'),
        ]);

        $result = $this->probe()->check('mail.example.com');

        $this->assertSame([
            'healthy' => false,
            'reason' => 'https://mail.example.com/up redirects to https://byomailer.example/up. The web server does not send this host to the app.',
        ], $result);
        Http::assertSentCount(1);
    }

    public function test_unhealthy_on_an_error_status(): void
    {
        Http::fake(['mail.example.com/*' => Http::response('', 526)]);

        $result = $this->probe()->check('mail.example.com');

        $this->assertSame([
            'healthy' => false,
            'reason' => 'https://mail.example.com/up returned HTTP 526. The app must answer with HTTP 200.',
        ], $result);
    }

    public function test_unhealthy_on_not_found(): void
    {
        Http::fake(['mail.example.com/*' => Http::response('', 404)]);

        $result = $this->probe()->check('mail.example.com');

        $this->assertFalse($result['healthy']);
        $this->assertStringContainsString('returned HTTP 404', $result['reason']);
    }

    public function test_unhealthy_on_a_timeout(): void
    {
        Http::fake(['mail.example.com/*' => Http::failedConnection('cURL error 28: Operation timed out')]);

        $result = $this->probe()->check('mail.example.com');

        $this->assertFalse($result['healthy']);
        $this->assertStringStartsWith('https://mail.example.com/up did not answer: ', $result['reason']);
        $this->assertStringContainsString('timed out', $result['reason']);
    }

    public function test_does_not_request_a_private_address(): void
    {
        Http::fake();

        $result = $this->probe('169.254.169.254')->check('mail.example.com');

        $this->assertSame([
            'healthy' => false,
            'reason' => 'mail.example.com does not resolve to a public IP address.',
        ], $result);
        Http::assertNothingSent();
    }

    public function test_does_not_request_a_domain_that_does_not_resolve(): void
    {
        Http::fake();

        $result = $this->probe(false)->check('mail.example.com');

        $this->assertSame([
            'healthy' => false,
            'reason' => 'mail.example.com does not resolve to a public IP address.',
        ], $result);
        Http::assertNothingSent();
    }
}
