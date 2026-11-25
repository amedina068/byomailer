<?php

namespace App\Console\Commands;

use App\Jobs\VerifyTrackingDomainJob;
use App\Models\EmailIdentity;
use Illuminate\Console\Command;

class CheckCustomTrackingDomainDns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-identities:check-tracking-domain-dns';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch per-identity verify jobs for unverified custom tracking domains.';

    public function handle(): int
    {
        $count = EmailIdentity::query()
            ->withoutGlobalScopes()
            ->whereNotNull('custom_tracking_domain')
            ->whereNull('custom_tracking_domain_verified_at')
            ->count();

        if ($count === 0) {
            $this->info('No unverified custom tracking domains to check.');

            return self::SUCCESS;
        }

        $this->info("Dispatching verify jobs for {$count} custom tracking domain(s)...");

        EmailIdentity::query()
            ->withoutGlobalScopes()
            ->whereNotNull('custom_tracking_domain')
            ->whereNull('custom_tracking_domain_verified_at')
            ->select(['id'])
            ->chunkById(500, function ($identities): void {
                foreach ($identities as $identity) {
                    VerifyTrackingDomainJob::dispatch($identity->id);
                }
            });

        return self::SUCCESS;
    }
}
