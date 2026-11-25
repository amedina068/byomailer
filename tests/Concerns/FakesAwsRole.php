<?php

namespace Tests\Concerns;

use App\Services\SesCredentials;
use Aws\Api\DateTimeResult;
use Aws\Result;
use Aws\Sts\StsClient;
use Mockery;
use Mockery\MockInterface;

/**
 * Turns on the IAM role connection with a fake STS client. The client gives
 * the temporary credentials of the AWS documentation for each AssumeRole call.
 */
trait FakesAwsRole
{
    protected const ROLE_PRINCIPAL_ARN = 'arn:aws:iam::111122223333:user/byomailer';

    protected const ROLE_ACCESS_KEY = 'ASIAIOSFODNN7EXAMPLE';

    protected const ROLE_SECRET_KEY = 'temporary-secret-EXAMPLE';

    protected const ROLE_SESSION_TOKEN = 'temporary-token-EXAMPLE';

    protected function fakeAwsRole(): StsClient&MockInterface
    {
        config([
            'services.ses_role.principal_arn' => self::ROLE_PRINCIPAL_ARN,
            'services.ses_role.key' => 'AKIAIOSFODNN7EXAMPLE',
            'services.ses_role.secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'services.ses_role.role_name' => 'byomailer-ses',
        ]);

        $sts = Mockery::mock(StsClient::class);
        $sts->shouldReceive('assumeRole')->andReturnUsing(fn () => new Result([
            'Credentials' => [
                'AccessKeyId' => self::ROLE_ACCESS_KEY,
                'SecretAccessKey' => self::ROLE_SECRET_KEY,
                'SessionToken' => self::ROLE_SESSION_TOKEN,
                'Expiration' => new DateTimeResult('@'.now()->addHour()->getTimestamp()),
            ],
        ]))->byDefault();

        $this->app->scoped(SesCredentials::class, function ($app) use ($sts): SesCredentials {
            $credentials = new SesCredentials($app['config']);
            $credentials->setStsClientFactory(fn () => $sts);

            return $credentials;
        });
        $this->app->forgetScopedInstances();

        return $sts;
    }
}
