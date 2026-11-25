<?php

namespace Tests\Feature;

use App\Models\EmailCredential;
use App\Models\User;
use App\Services\UserMailerFactory;
use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * `phpunit.xml` sends every AWS SDK call to a closed local port. A test that does
 * not fake an AWS service cannot reach AWS, also when the database holds real keys.
 */
class AwsCallsAreBlockedTest extends TestCase
{
    use RefreshDatabase;

    private const BLOCKED_HOST = 'aws-calls-are-blocked-in-tests.localhost';

    public function test_an_sdk_client_built_like_the_services_build_it_cannot_reach_aws(): void
    {
        $client = new SesV2Client([
            'version' => '2019-09-27',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'AKIAEXAMPLEEXAMPLE00', 'secret' => 'example-secret-key'],
        ]);
        $startedAt = microtime(true);

        try {
            $client->getAccount();
            $this->fail('The AWS call was not refused.');
        } catch (AwsException $exception) {
            $this->assertStringContainsString(self::BLOCKED_HOST, $exception->getMessage());
        }

        $this->assertLessThan(1.0, microtime(true) - $startedAt, 'A blocked AWS call must fail at once, with no retry.');
    }

    public function test_a_campaign_mail_cannot_reach_ses(): void
    {
        config(['mail.use_ses_api' => true]);
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create(['region' => 'us-east-1']);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage(self::BLOCKED_HOST);

        app(UserMailerFactory::class)->mailerFor($user)
            ->to('contact@example.com')
            ->send((new Mailable)->from('sender@example.com')->subject('Hello')->html('<p>Hello</p>'));
    }
}
