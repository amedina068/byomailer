<?php

namespace App\Console\Commands;

use App\Models\EmailIdentity;
use App\Services\LinkDomainVerifier;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class CheckCustomLinkDomains extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-identities:check-link-domain';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Activate custom link domains once Cloudflare for SaaS has issued their certificate and a request through the domain reaches the app.';

    public function __construct(private LinkDomainVerifier $verifier)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = $this->unverified()->count();

        if ($count === 0) {
            $this->info('No unverified custom link domains to check.');

            return self::SUCCESS;
        }

        $this->info("Checking {$count} custom link domain(s)...");

        $this->unverified()->chunkById(100, function ($identities): void {
            foreach ($identities as $identity) {
                $result = $this->verifier->verify($identity);

                if ($result['verified']) {
                    Cache::forget("link-domain-host:{$identity->custom_link_domain}");
                    Cache::forget("link-domain-check:{$identity->id}");
                    $this->info("{$identity->custom_link_domain}: verified and activated.");
                } elseif (isset($result['error'])) {
                    $this->error("{$identity->custom_link_domain}: {$result['error']}");
                } else {
                    $this->line("{$identity->custom_link_domain}: Cloudflare certificate not yet active.");
                }
            }
        });

        return self::SUCCESS;
    }

    /**
     * Custom link domains awaiting their first Cloudflare activation.
     */
    private function unverified(): Builder
    {
        return EmailIdentity::query()
            ->withoutGlobalScopes()
            ->whereNotNull('custom_link_domain')
            ->whereNull('custom_link_domain_verified_at');
    }
}
