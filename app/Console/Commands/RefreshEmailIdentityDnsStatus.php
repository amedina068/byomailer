<?php

namespace App\Console\Commands;

use App\Enums\DnsRecordPurpose;
use App\Enums\IdentityStatus;
use App\Exceptions\AwsCredentialsException;
use App\Models\EmailIdentity;
use App\Services\DmarcVerifier;
use App\Services\EmailIdentityStatusSyncService;
use App\Services\SesCredentials;
use App\Services\SesDomainIdentityService;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RefreshEmailIdentityDnsStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-identities:refresh-dns-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh DNS verification status for pending email identities.';

    public function __construct(
        private SesDomainIdentityService $domainIdentityService,
        private DmarcVerifier $dmarcVerifier,
        private EmailIdentityStatusSyncService $statusSyncService,
        private SesCredentials $sesCredentials,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $identities = EmailIdentity::withoutGlobalScopes()
            ->with(['user.sesCredential' => fn ($query) => $query->withoutGlobalScopes(), 'dnsRecords'])
            ->whereNotNull('domain')
            ->where(function (Builder $query): void {
                $query->whereNull('identity_status')
                    ->orWhereIn('identity_status', [
                        IdentityStatus::Pending,
                        IdentityStatus::Verifying,
                    ])
                    // Already-verified identities are re-checked only for
                    // DMARC: SES can verify an identity long before the
                    // customer publishes `_dmarc`, and nothing else would ever
                    // notice when they finally do — or when they remove it
                    // again. Re-checking a confirmed record bumps its
                    // timestamp, so this settles into one DNS query per day
                    // per identity.
                    ->orWhereHas('dnsRecords', fn (Builder $records) => $records
                        ->where('purpose', DnsRecordPurpose::Dmarc)
                        ->where(fn (Builder $stale) => $stale
                            ->whereNull('verified_at')
                            ->orWhere('verified_at', '<=', now()->subDay())
                        )
                    );
            })
            ->get();

        if ($identities->isEmpty()) {
            $this->info('No pending email identities to check.');

            return self::SUCCESS;
        }

        $this->info("Checking {$identities->count()} email identities...");

        foreach ($identities as $identity) {
            Tenant::run($identity->client_id, function () use ($identity): void {
                $this->refreshIdentity($identity);
                $this->refreshDmarc($identity);
            });
        }

        return self::SUCCESS;
    }

    private function refreshIdentity(EmailIdentity $identity): void
    {
        if ($identity->identity_status === IdentityStatus::Verified) {
            return;
        }

        $credential = $identity->user->sesCredential;

        if (! $credential) {
            $this->warn("{$identity->email}: No AWS credentials found, skipping.");

            return;
        }

        try {
            $aws = $this->sesCredentials->for($credential);
        } catch (AwsCredentialsException $exception) {
            $this->error("{$identity->email}: {$exception->getMessage()}");

            return;
        }

        $result = $this->domainIdentityService->getIdentityStatus(
            $identity->domain,
            $aws->getAccessKeyId(),
            $aws->getSecretKey(),
            $credential->region,
            $aws->getSecurityToken()
        );

        if (! $result['success']) {
            $this->error("{$identity->email}: {$result['error']}");

            return;
        }

        $this->statusSyncService->applyAwsStatus($identity, $result);

        if ($result['status'] === 'verified') {
            $this->info("{$identity->email}: Verified!");
        } else {
            $this->line("{$identity->email}: {$identity->identity_status->value} (DKIM: {$result['dkim_status']}, MAIL FROM: {$result['mail_from_status']})");
        }
    }

    /**
     * Resolve `_dmarc.<domain>` and mark the DMARC record verified once the
     * customer publishes a policy. A published `p=none` counts as verified —
     * it satisfies the bulk-sender requirement — but it is reported so the
     * weak policy is visible in the command output.
     */
    private function refreshDmarc(EmailIdentity $identity): void
    {
        // Read the eager-loaded collection, not the relation query. This runs
        // for every verified identity with a stale DMARC record, every 15
        // minutes, so a fresh query per identity is a real N+1.
        $record = $identity->dnsRecords->firstWhere('purpose', DnsRecordPurpose::Dmarc);

        if (! $record) {
            return;
        }

        $result = $this->dmarcVerifier->check((string) $identity->domain);
        $published = $result['published'] && $result['policy'] !== null;

        $record->forceFill(['verified_at' => $published ? now() : null])->save();

        if (! $published) {
            $this->warn("{$identity->email}: no DMARC policy published at _dmarc.{$identity->domain}.");

            return;
        }

        if ($result['policy'] === 'none') {
            $this->line("{$identity->email}: DMARC published (p=none — consider quarantine).");

            return;
        }

        $this->line("{$identity->email}: DMARC published (p={$result['policy']}).");
    }
}
