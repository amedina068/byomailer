<?php

namespace App\Support\Instance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use stdClass;

/**
 * The Amazon SES checks of `byomailer:doctor`.
 *
 * BYOMailer sends each email through the SES account of the account (client)
 * that owns it. A new instance has no SES account yet, so every check here is
 * a `warn` with a fix, never a `fail`. The checks read the stored state only
 * (`SesStatus`) and make no AWS call.
 */
class SesChecks
{
    /** A message names at most this many accounts. */
    private const ACCOUNTS_SHOWN = 10;

    /** @var Collection<int, stdClass> */
    private Collection $credentials;

    public function __construct(private SesStatus $ses) {}

    /**
     * @return list<array{id: string, status: string, message: string, fix: string}>
     */
    public function run(): array
    {
        $this->credentials = $this->ses->credentials();

        return [
            $this->credentialsCheck(),
            $this->regionCheck(),
            $this->quotaCheck(),
            $this->identityCheck(),
            $this->eventsCheck(),
        ];
    }

    /**
     * @return array{id: string, status: string, message: string, fix: string}
     */
    private function credentialsCheck(): array
    {
        $count = $this->credentials->count();

        if ($count === 0) {
            return DoctorCheck::warn('ses_credentials', 'No account has AWS credentials for SES yet. BYOMailer cannot send email.', $this->credentialsFix());
        }

        return DoctorCheck::ok('ses_credentials', $count === 1 ? '1 account has AWS credentials for SES.' : "{$count} accounts have AWS credentials for SES.");
    }

    /**
     * @return array{id: string, status: string, message: string, fix: string}
     */
    private function regionCheck(): array
    {
        if ($this->credentials->isEmpty()) {
            return DoctorCheck::warn('ses_region', 'No SES region is set, because no account has AWS credentials.', $this->credentialsFix());
        }

        $invalid = $this->credentials->reject(fn (object $row): bool => SesStatus::regionIsValid($row->region));

        if ($invalid->isNotEmpty()) {
            return DoctorCheck::warn(
                'ses_region',
                'These accounts have an SES region that is not an AWS region: '.$this->names($invalid, fn (object $row): string => (string) $row->region).'.',
                'Open '.$this->url('/settings/email-credentials').' in each account and select the region of the SES account, for example us-east-1.',
            );
        }

        return DoctorCheck::ok('ses_region', 'Each account uses a valid SES region: '.$this->credentials->pluck('region')->unique()->implode(', ').'.');
    }

    /**
     * @return array{id: string, status: string, message: string, fix: string}
     */
    private function quotaCheck(): array
    {
        if ($this->credentials->isEmpty()) {
            return DoctorCheck::warn('ses_quota', 'The SES send quota is not known, because no account has AWS credentials.', $this->credentialsFix());
        }

        $unknown = $this->credentials->whereNull('quota_fetched_at');

        if ($unknown->isNotEmpty()) {
            return DoctorCheck::warn('ses_quota', 'BYOMailer did not read the SES send quota of these accounts yet: '.$this->names($unknown).'.', 'Run php artisan ses:fetch-quota. The scheduler also reads the quota each hour.');
        }

        $stopped = $this->credentials->reject(fn (object $row): bool => (bool) $row->sending_enabled);

        if ($stopped->isNotEmpty()) {
            return DoctorCheck::warn('ses_quota', 'AWS paused sending for these accounts: '.$this->names($stopped).'.', 'Open the SES console of the region, read the reason on the account dashboard, and ask AWS to resume sending.');
        }

        $sandbox = $this->credentials->reject(fn (object $row): bool => (bool) $row->production_access_enabled);

        if ($sandbox->isNotEmpty()) {
            return DoctorCheck::warn(
                'ses_quota',
                'These accounts are in the SES sandbox: '.$this->names($sandbox, fn (object $row): string => "{$row->max_24_hour_send} emails each 24 hours").'. In the sandbox, SES sends only to verified addresses.',
                'In the SES console of the region, open Account dashboard and request production access.',
            );
        }

        return DoctorCheck::ok('ses_quota', 'Each account has SES production access. The quota was read at '.$this->newestQuotaTime().'.');
    }

    /**
     * @return array{id: string, status: string, message: string, fix: string}
     */
    private function identityCheck(): array
    {
        $identityFix = 'Open '.$this->url('/settings/identities').', add the sending domain, and publish the DNS records that BYOMailer shows. SES verifies the domain after the records are live.';

        if ($this->credentials->isEmpty()) {
            return DoctorCheck::warn('ses_identity', 'No account has a verified sender identity, because no account has AWS credentials.', $this->credentialsFix().' Then '.lcfirst($identityFix));
        }

        $verified = $this->ses->verifiedEmails($this->credentials->pluck('client_id')->all());
        $missing = $this->credentials->reject(fn (object $row): bool => isset($verified[$row->client_id]));

        if ($missing->isNotEmpty()) {
            return DoctorCheck::warn('ses_identity', 'These accounts have no verified sender identity: '.$this->names($missing).'. They cannot send email.', $identityFix);
        }

        return DoctorCheck::ok('ses_identity', 'Each account with AWS credentials has a verified sender identity.');
    }

    /**
     * @return array{id: string, status: string, message: string, fix: string}
     */
    private function eventsCheck(): array
    {
        $events = $this->ses->events();

        if ($events['configured']) {
            return DoctorCheck::ok('ses_events', 'The SQS queue for SES events is set. The ses-events:poll process reads it.');
        }

        return DoctorCheck::warn(
            'ses_events',
            'The SQS queue for SES events is not set. BYOMailer sends email, but it does not record bounces, complaints, deliveries, opens or clicks.',
            'Set '.implode(', ', $events['missing']).' in the environment, then restart the app. The SES setup guide in the self-hosting docs shows how to create the queue.',
        );
    }

    private function credentialsFix(): string
    {
        return 'Log in, open '.$this->url('/settings/email-credentials').', and enter the access key of an IAM user that can use SES.';
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @param  (callable(object): string)|null  $detail
     */
    private function names(Collection $rows, ?callable $detail = null): string
    {
        $shown = $rows->take(self::ACCOUNTS_SHOWN)
            ->map(fn (object $row): string => ($row->account_name ?? 'account')." (id {$row->client_id}".($detail ? ', '.$detail($row) : '').')')
            ->implode(', ');
        $more = $rows->count() - self::ACCOUNTS_SHOWN;

        return $more > 0 ? "{$shown} and {$more} more" : $shown;
    }

    private function newestQuotaTime(): string
    {
        return Carbon::parse($this->credentials->max('quota_fetched_at'))->toIso8601String();
    }

    private function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
