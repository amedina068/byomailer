<?php

namespace App\Services;

use App\Exceptions\AwsCredentialsException;
use App\Models\EmailCredential;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\Sts\StsClient;
use Closure;
use DateTimeInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives the AWS credentials of an account: its access keys, or the temporary
 * credentials of the IAM role that BYOMailer assumes in its AWS account.
 *
 * The temporary credentials stay encrypted in the cache. BYOMailer asks STS
 * for new credentials 5 minutes before the old credentials expire.
 */
class SesCredentials
{
    public const SESSION_SECONDS = 3600;

    public const REFRESH_SECONDS = 300;

    /**
     * @var array<string, Credentials>
     */
    private array $resolved = [];

    private ?Closure $stsClientFactory = null;

    public function __construct(private Repository $config) {}

    /**
     * @param  Closure(array<string, mixed>): StsClient  $factory
     */
    public function setStsClientFactory(Closure $factory): void
    {
        $this->stsClientFactory = $factory;
    }

    public function roleConnectionEnabled(): bool
    {
        return filled($this->config->get('services.ses_role.principal_arn'));
    }

    public function roleArnFor(string $accountId): string
    {
        return 'arn:aws:iam::'.$accountId.':role/'.$this->config->get('services.ses_role.role_name');
    }

    /**
     * Get the credentials that sign the AWS calls of the account.
     *
     * @throws AwsCredentialsException
     */
    public function for(EmailCredential $credential): Credentials
    {
        if (! $credential->usesRole()) {
            if (blank($credential->access_key) || blank($credential->secret_key)) {
                throw new AwsCredentialsException('This account has no AWS credentials.');
            }

            return new Credentials($credential->access_key, $credential->secret_key);
        }

        $cacheKey = self::cacheKey($credential);

        if (isset($this->resolved[$cacheKey]) && $this->isFresh($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        return $this->resolved[$cacheKey] = $this->cached($cacheKey)
            ?? $this->remember($cacheKey, $this->assumeRole(
                $credential->role_arn,
                $credential->client->awsExternalId(),
                'byomailer-client-'.$credential->client_id,
            ));
    }

    /**
     * Get the value for the `credentials` option of an AWS SDK client.
     *
     * A role gives a provider. The SDK calls it again when the credentials
     * expire, so a long send gets new credentials on its way.
     *
     * @return Credentials|callable(): PromiseInterface|false
     */
    public function clientCredentials(EmailCredential $credential): Credentials|callable|false
    {
        if ($credential->usesRole()) {
            return function () use ($credential): PromiseInterface {
                try {
                    return Create::promiseFor($this->for($credential));
                } catch (Throwable $exception) {
                    return Create::rejectionFor($exception);
                }
            };
        }

        if (blank($credential->access_key) || blank($credential->secret_key)) {
            return false;
        }

        return $this->for($credential);
    }

    /**
     * Assume an IAM role of an account now, with no cache.
     *
     * @throws AwsCredentialsException
     */
    public function assumeRole(string $roleArn, string $externalId, string $sessionName): Credentials
    {
        if (! $this->roleConnectionEnabled()) {
            throw new AwsCredentialsException('The IAM role connection is not set up on this BYOMailer server.');
        }

        try {
            $result = $this->stsClient()->assumeRole([
                'RoleArn' => $roleArn,
                'ExternalId' => $externalId,
                'RoleSessionName' => $sessionName,
                'DurationSeconds' => self::SESSION_SECONDS,
            ]);
        } catch (AwsException $exception) {
            Log::warning('AWS refused an AssumeRole call.', [
                'error_code' => $exception->getAwsErrorCode(),
                'session' => $sessionName,
            ]);

            throw new AwsCredentialsException($exception->getAwsErrorCode() === 'AccessDenied'
                ? 'AWS refused to let BYOMailer use the IAM role. Make sure that the stack is complete and that it uses the external ID of this account. A new role can need 1 minute before it works.'
                : 'BYOMailer could not use the IAM role. Try again in 1 minute.');
        }

        $expiration = $result['Credentials']['Expiration'];
        $expiresAt = $expiration instanceof DateTimeInterface ? $expiration->getTimestamp() : (int) strtotime((string) $expiration);

        return new Credentials(
            $result['Credentials']['AccessKeyId'],
            $result['Credentials']['SecretAccessKey'],
            $result['Credentials']['SessionToken'],
            $expiresAt - self::REFRESH_SECONDS,
        );
    }

    /**
     * The key includes the role and the external ID, so a new role never
     * gets the credentials of the old role.
     */
    public static function cacheKey(EmailCredential $credential): string
    {
        return 'ses-role-credentials:'.$credential->id.':'.hash('sha256', $credential->role_arn.'|'.$credential->client->awsExternalId());
    }

    private function cached(string $cacheKey): ?Credentials
    {
        $encrypted = Cache::get($cacheKey);

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            $values = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $credentials = new Credentials($values['key'], $values['secret'], $values['token'], $values['expires']);

        return $this->isFresh($credentials) ? $credentials : null;
    }

    private function remember(string $cacheKey, Credentials $credentials): Credentials
    {
        Cache::put($cacheKey, Crypt::encryptString(json_encode([
            'key' => $credentials->getAccessKeyId(),
            'secret' => $credentials->getSecretKey(),
            'token' => $credentials->getSecurityToken(),
            'expires' => $credentials->getExpiration(),
        ], JSON_THROW_ON_ERROR)), max(1, $credentials->getExpiration() - now()->getTimestamp()));

        return $credentials;
    }

    private function isFresh(Credentials $credentials): bool
    {
        return $credentials->getExpiration() > now()->getTimestamp();
    }

    private function stsClient(): StsClient
    {
        $config = [
            'version' => '2011-06-15',
            'region' => $this->config->get('services.ses_role.region'),
        ];

        if (filled($this->config->get('services.ses_role.key')) && filled($this->config->get('services.ses_role.secret'))) {
            $config['credentials'] = [
                'key' => $this->config->get('services.ses_role.key'),
                'secret' => $this->config->get('services.ses_role.secret'),
            ];
        }

        return $this->stsClientFactory
            ? ($this->stsClientFactory)($config)
            : new StsClient($config);
    }
}
