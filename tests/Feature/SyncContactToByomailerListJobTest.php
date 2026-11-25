<?php

namespace Tests\Feature;

use App\Jobs\SyncContactToByomailerList;
use App\Services\ByomailerClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncContactToByomailerListJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.byomailer.api_url' => 'https://byomailer.test/api',
            'services.byomailer.api_token' => 'test-token',
            'services.byomailer.list_id' => '42',
        ]);
    }

    public function test_job_posts_payload_to_byomailer_api(): void
    {
        Http::fake([
            'byomailer.test/*' => Http::response(['id' => 1], 201),
        ]);

        (new SyncContactToByomailerList(
            email: 'a@example.com',
            name: 'A User',
            tags: ['free'],
            extraAttributes: ['plan' => 'pro', 'interval' => 'monthly'],
        ))->handle(app(ByomailerClient::class));

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://byomailer.test/api/v1/lists/42/contacts'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request->hasHeader('Accept', 'application/json')
                && $request['email'] === 'a@example.com'
                && $request['name'] === 'A User'
                && $request['tags'] === ['free']
                && $request['plan'] === 'pro'
                && $request['interval'] === 'monthly';
        });
    }

    public function test_job_noops_when_config_missing(): void
    {
        config([
            'services.byomailer.api_url' => null,
            'services.byomailer.api_token' => null,
            'services.byomailer.list_id' => null,
        ]);

        Http::fake();

        (new SyncContactToByomailerList(
            email: 'a@example.com',
            name: null,
            tags: ['free'],
        ))->handle(new ByomailerClient);

        Http::assertNothingSent();
    }

    public function test_job_releases_on_failed_response(): void
    {
        Http::fake([
            'byomailer.test/*' => Http::response(['error' => 'boom'], 500),
        ]);

        $job = $this->getMockBuilder(SyncContactToByomailerList::class)
            ->setConstructorArgs([
                'email' => 'a@example.com',
                'name' => null,
                'tags' => ['free'],
                'extraAttributes' => [],
            ])
            ->onlyMethods(['release'])
            ->getMock();

        $job->expects($this->once())->method('release')->with(120);

        $job->handle(app(ByomailerClient::class));
    }
}
