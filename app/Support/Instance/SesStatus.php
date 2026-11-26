<?php

namespace App\Support\Instance;

use App\Enums\IdentityStatus;
use App\Models\Client;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use stdClass;

/**
 * The Amazon SES state of the accounts of this instance, from the database.
 *
 * Each account (client) enters its own AWS keys in Settings, AWS credentials.
 * The scheduler reads the send quota each hour (`ses:fetch-quota`). This class
 * reads only the stored values, so it makes no AWS call and it is fast.
 *
 * It never decrypts the AWS keys. The query only checks that the two key
 * columns have a value.
 */
class SesStatus
{
    /** The environment variables of the SQS queue that receives the SES events. */
    public const EVENT_VARIABLES = [
        'key' => 'PLATFORM_AWS_ACCESS_KEY_ID',
        'secret' => 'PLATFORM_AWS_SECRET_ACCESS_KEY',
        'region' => 'PLATFORM_AWS_REGION',
        'queue_url' => 'SES_EVENTS_SQS_QUEUE_URL',
    ];

    /** The shape of an AWS region name, for example `us-east-1` or `us-gov-west-1`. */
    private const REGION_PATTERN = '/^[a-z]{2}(-gov|-iso[a-z]?)?-[a-z]+-\d+$/';

    /** The response lists at most this many verified emails for each account. */
    private const EMAILS_SHOWN = 10;

    /**
     * @return array{accounts: int, accounts_with_credentials: int, verified_identities: int, events_configured: bool}
     */
    public function summary(): array
    {
        return [
            'accounts' => Client::query()->count(),
            'accounts_with_credentials' => $this->credentials()->count(),
            'verified_identities' => EmailIdentity::withoutGlobalScopes()->where('identity_status', IdentityStatus::Verified)->count(),
            'events_configured' => $this->events()['configured'],
        ];
    }

    /**
     * The SQS path of the SES events: SES, then SNS, then SQS, then `ses-events:poll`.
     *
     * @return array{configured: bool, missing: list<string>, region: string|null}
     */
    public function events(): array
    {
        $config = (array) config('services.sqs_ses_events');
        $missing = [];

        foreach (self::EVENT_VARIABLES as $key => $variable) {
            if (blank($config[$key] ?? null)) {
                $missing[] = $variable;
            }
        }

        return ['configured' => $missing === [], 'missing' => $missing, 'region' => $config['region'] ?? null];
    }

    /**
     * The SES credentials that have both AWS keys, one row for each account,
     * with the name of the account.
     *
     * @return Collection<int, stdClass>
     */
    public function credentials(?int $clientId = null): Collection
    {
        return EmailCredential::withoutGlobalScopes()
            ->toBase()
            ->leftJoin('clients', 'clients.id', '=', 'email_credentials.client_id')
            ->where('email_credentials.type', 'ses')
            ->whereNotNull('email_credentials.access_key')
            ->where('email_credentials.access_key', '!=', '')
            ->whereNotNull('email_credentials.secret_key')
            ->where('email_credentials.secret_key', '!=', '')
            ->when($clientId !== null, fn ($query) => $query->where('email_credentials.client_id', $clientId))
            ->orderBy('email_credentials.client_id')
            ->get([
                'email_credentials.client_id',
                'clients.name as account_name',
                'email_credentials.region',
                'email_credentials.production_access_enabled',
                'email_credentials.sending_enabled',
                'email_credentials.enforcement_status',
                'email_credentials.max_24_hour_send',
                'email_credentials.max_send_rate',
                'email_credentials.sent_last_24_hours',
                'email_credentials.quota_fetched_at',
                'email_credentials.configuration_set_name',
                'email_credentials.sns_subscription_confirmed_at',
            ])
            ->unique('client_id')
            ->values();
    }

    /**
     * The verified sender emails of each account.
     *
     * @param  list<int>  $clientIds
     * @return array<int, list<string>>
     */
    public function verifiedEmails(array $clientIds): array
    {
        return EmailIdentity::withoutGlobalScopes()
            ->whereIn('client_id', $clientIds)
            ->where('identity_status', IdentityStatus::Verified)
            ->orderBy('id')
            ->get(['client_id', 'email'])
            ->groupBy('client_id')
            ->map(fn (Collection $identities): array => $identities->pluck('email')->all())
            ->all();
    }

    /**
     * The SES state of each account, for the `get-ses-status` and
     * `list-accounts` tools.
     *
     * @return list<array<string, mixed>>
     */
    public function accounts(?int $clientId = null, int $limit = 100): array
    {
        $clients = Client::query()
            ->when($clientId !== null, fn ($query) => $query->whereKey($clientId))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name']);

        $ids = $clients->pluck('id')->all();
        $credentials = $this->credentials()->whereIn('client_id', $ids)->keyBy('client_id');
        $verified = $this->verifiedEmails($ids);
        $totals = EmailIdentity::withoutGlobalScopes()->whereIn('client_id', $ids)
            ->selectRaw('client_id, count(*) as total')->groupBy('client_id')->pluck('total', 'client_id');

        return $clients->map(fn (Client $client): array => $this->describe(
            $client,
            $credentials->get($client->id),
            $verified[$client->id] ?? [],
            (int) ($totals[$client->id] ?? 0),
        ))->values()->all();
    }

    public static function regionIsValid(?string $region): bool
    {
        return is_string($region) && preg_match(self::REGION_PATTERN, $region) === 1;
    }

    /**
     * @param  list<string>  $verifiedEmails
     * @return array<string, mixed>
     */
    private function describe(Client $client, ?object $credential, array $verifiedEmails, int $identities): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'credentials' => $credential !== null,
            'region' => $credential?->region,
            'region_valid' => $credential ? self::regionIsValid($credential->region) : null,
            'quota' => $credential?->quota_fetched_at ? [
                'fetched_at' => Carbon::parse($credential->quota_fetched_at)->toIso8601String(),
                'production_access' => (bool) $credential->production_access_enabled,
                'sending_enabled' => (bool) $credential->sending_enabled,
                'enforcement_status' => $credential->enforcement_status,
                'max_24_hour_send' => (int) $credential->max_24_hour_send,
                'max_send_rate' => (int) $credential->max_send_rate,
                'sent_last_24_hours' => (int) $credential->sent_last_24_hours,
            ] : null,
            'identities' => [
                'total' => $identities,
                'verified' => count($verifiedEmails),
                'verified_emails' => array_slice($verifiedEmails, 0, self::EMAILS_SHOWN),
            ],
            'events' => [
                'configuration_set' => filled($credential?->configuration_set_name),
                'sns_subscription_confirmed' => filled($credential?->sns_subscription_confirmed_at),
            ],
        ];
    }
}
