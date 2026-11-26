<?php

namespace Tests\Unit;

use App\Exceptions\AwsCredentialsException;
use App\Models\EmailCredential;
use App\Models\User;
use App\Services\SesCredentials;
use Aws\Api\DateTimeResult;
use Aws\Command;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sts\StsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SesCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const PRINCIPAL_ARN = 'arn:aws:iam::111122223333:user/byomailer';

    private const ROLE_ARN = 'arn:aws:iam::123456789012:role/byomailer-ses';

    /**
     * @var list<array<string, mixed>>
     */
    private array $stsConfigs = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ses_role.principal_arn' => self::PRINCIPAL_ARN,
            'services.ses_role.key' => 'AKIAIOSFODNN7EXAMPLE',
            'services.ses_role.secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'services.ses_role.region' => 'us-east-1',
        ]);
    }

    public function test_a_key_credential_returns_its_keys_without_a_call_to_sts(): void
    {
        $credential = $this->credential([
            'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);
        $sts = Mockery::mock(StsClient::class);
        $sts->shouldNotReceive('assumeRole');

        $credentials = $this->service($sts)->for($credential);

        $this->assertSame('AKIAIOSFODNN7EXAMPLE', $credentials->getAccessKeyId());
        $this->assertSame('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', $credentials->getSecretKey());
        $this->assertNull($credentials->getSecurityToken());
    }

    public function test_a_role_credential_assumes_the_role_with_the_external_id_of_its_client(): void
    {
        $credential = $this->credential(['role_arn' => self::ROLE_ARN, 'access_key' => null, 'secret_key' => null]);
        $externalId = $credential->client->awsExternalId();
        $sts = $this->sts();

        $credentials = $this->service($sts)->for($credential);

        $this->assertSame('ASIAIOSFODNN7EXAMPLE', $credentials->getAccessKeyId());
        $this->assertSame('temporary-secret-EXAMPLE', $credentials->getSecretKey());
        $this->assertSame('temporary-token-EXAMPLE', $credentials->getSecurityToken());
        $sts->shouldHaveReceived('assumeRole')->once()->with([
            'RoleArn' => self::ROLE_ARN,
            'ExternalId' => $externalId,
            'RoleSessionName' => 'byomailer-client-'.$credential->client_id,
            'DurationSeconds' => 3600,
        ]);
    }

    public function test_the_sts_client_signs_with_the_keys_of_the_principal(): void
    {
        $credential = $this->credential(['role_arn' => self::ROLE_ARN, 'access_key' => null, 'secret_key' => null]);

        $this->service($this->sts())->for($credential);

        $this->assertSame([
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ], $this->stsConfigs[0]['credentials']);
        $this->assertSame('us-east-1', $this->stsConfigs[0]['region']);
    }

    public function test_without_principal_keys_the_sdk_finds_the_credentials_itself(): void
    {
        config(['services.ses_role.key' => null, 'services.ses_role.secret' => null]);
        $credential = $this->credential(['role_arn' => self::ROLE_ARN, 'access_key' => null, 'secret_key' => null]);

        $this->service($this->sts())->for($credential);

        $this->assertArrayNotHasKey('credentials', $this->stsConfigs[0]);
    }

    public function test_the_temporary_credentials_come_from_the_cache_until_they_are_close_to_expiry(): void
    {
        $credential = $this->credential(['role_arn' => self::ROLE_ARN, 'access_key' => null, 'secret_key' => null]);
        $sts = $this->sts();

        $this->service($sts)->for($credential);
        $this->service($sts)->for($credential);

        $sts->shouldHaveReceived('assumeRole')->once();

        $this->travel(56)->minutes();
        $this->service($sts)->for($credential);

        $sts->shouldHaveReceived('assumeRole')->twice();
    }

    public function test_the_service_refreshes_its_own_copy_before_the_credentials_expire(): void
    {
        $credential = $this->credential(['role_arn' => self::ROLE_ARN, 'access_key' => null, 'secret_key' => null]);
        $sts = $this->sts();
        $service = $this->service($sts);

        $service->for($credential);
        $this->travel(54)->minutes();
        $service->for($credential);

        $sts->shouldHaveReceived('assumeRole')->once();

        $this->travel(2)->minutes();
        Cache::flush();
        $service->for($credential);

        $sts->shouldHaveReceived('assumeRole')->twice();
    }

    public function test_the_cache_holds_the_temporary_credentials_encrypted(): void
    {
        $credential = $this->credential(['role_arn' => self::ROLE_ARN, 'access_key' => null, 'secret_key' => null]);

        $this->service($this->sts())->for($credential);

        $stored = Cache::get(SesCredentials::cacheKey($credential));

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('temporary-secret-EXAMPLE', $stored);
        $this->assertStringNotContainsString('temporary-token-EXAMPLE', $stored);
    }

    public function test_a_new_role_arn_does_not_reuse_the_cached_credentials(): void
    {
        $credential = $this->credential(['role_arn' => self::ROLE_ARN, 'access_key' => null, 'secret_key' => null]);
        $sts = $this->sts();

        $this->service($sts)->for($credential);
        $credential->role_arn = 'arn:aws:iam::123456789012:role/other';
        $this->service($sts)->for($credential);

        $sts->shouldHaveReceived('assumeRole')->twice();
    }

    public function test_a_refused_assume_role_gives_a_safe_message(): void
    {
        $credential = $this->credential(['role_arn' => self::ROLE_ARN, 'access_key' => null, 'secret_key' => null]);
        $sts = Mockery::mock(StsClient::class);
        $sts->shouldReceive('assumeRole')->andThrow(new AwsException(
            'User: '.self::PRINCIPAL_ARN.' is not authorized to perform: sts:AssumeRole',
            new Command('AssumeRole'),
            ['code' => 'AccessDenied', 'message' => 'User: '.self::PRINCIPAL_ARN.' is not authorized to perform: sts:AssumeRole'],
        ));

        try {
            $this->service($sts)->for($credential);
            $this->fail('The exception did not occur.');
        } catch (AwsCredentialsException $exception) {
            $this->assertStringContainsString('AWS refused to let BYOMailer use the IAM role', $exception->getMessage());
            $this->assertStringNotContainsString(self::PRINCIPAL_ARN, $exception->getMessage());
        }
    }

    public function test_a_role_credential_fails_when_the_server_has_no_role_principal(): void
    {
        config(['services.ses_role.principal_arn' => null]);
        $credential = $this->credential(['role_arn' => self::ROLE_ARN, 'access_key' => null, 'secret_key' => null]);
        $sts = Mockery::mock(StsClient::class);
        $sts->shouldNotReceive('assumeRole');

        $this->expectException(AwsCredentialsException::class);
        $this->expectExceptionMessage('The IAM role connection is not set up on this BYOMailer server.');

        $this->service($sts)->for($credential);
    }

    public function test_a_credential_with_no_keys_and_no_role_fails_with_a_clear_message(): void
    {
        $credential = $this->credential(['access_key' => null, 'secret_key' => null]);

        $this->expectException(AwsCredentialsException::class);
        $this->expectExceptionMessage('This account has no AWS credentials.');

        $this->service(Mockery::mock(StsClient::class))->for($credential);
    }

    public function test_an_sdk_client_gets_a_provider_that_resolves_the_temporary_credentials(): void
    {
        $credential = $this->credential(['role_arn' => self::ROLE_ARN, 'access_key' => null, 'secret_key' => null]);

        $provider = $this->service($this->sts())->clientCredentials($credential);

        $this->assertIsCallable($provider);
        $resolved = $provider()->wait();
        $this->assertInstanceOf(Credentials::class, $resolved);
        $this->assertSame('temporary-token-EXAMPLE', $resolved->getSecurityToken());
    }

    public function test_an_sdk_client_gets_no_credentials_when_the_account_has_none(): void
    {
        $credential = $this->credential(['access_key' => null, 'secret_key' => null]);

        $this->assertFalse($this->service(Mockery::mock(StsClient::class))->clientCredentials($credential));
    }

    public function test_the_role_arn_uses_the_account_id_and_the_configured_role_name(): void
    {
        config(['services.ses_role.role_name' => 'byomailer-ses']);

        $this->assertSame(self::ROLE_ARN, $this->service(Mockery::mock(StsClient::class))->roleArnFor('123456789012'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function credential(array $attributes): EmailCredential
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        return EmailCredential::factory()->for($user)->create($attributes);
    }

    private function sts(): StsClient&MockInterface
    {
        $sts = Mockery::mock(StsClient::class);
        $sts->shouldReceive('assumeRole')->andReturnUsing(fn () => new Result([
            'Credentials' => [
                'AccessKeyId' => 'ASIAIOSFODNN7EXAMPLE',
                'SecretAccessKey' => 'temporary-secret-EXAMPLE',
                'SessionToken' => 'temporary-token-EXAMPLE',
                'Expiration' => new DateTimeResult('@'.now()->addHour()->getTimestamp()),
            ],
            'AssumedRoleUser' => ['Arn' => 'arn:aws:sts::123456789012:assumed-role/byomailer-ses/byomailer-client-1'],
        ]));

        return $sts;
    }

    private function service(StsClient $sts): SesCredentials
    {
        $service = new SesCredentials(config());
        $service->setStsClientFactory(function (array $config) use ($sts) {
            $this->stsConfigs[] = $config;

            return $sts;
        });

        return $service;
    }
}
