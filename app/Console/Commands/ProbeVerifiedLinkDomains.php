<?php

namespace App\Console\Commands;

use App\Exceptions\LinkDomainUnhealthyException;
use App\Models\EmailIdentity;
use App\Services\LinkDomainProbe;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Probes each verified custom link domain through the domain and reports each
 * failure to the operator. The customer cannot correct the origin web server.
 * The command never clears `custom_link_domain_verified_at`, because sent mail
 * then falls back to links on the app host.
 */
class ProbeVerifiedLinkDomains extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-identities:probe-link-domains';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a request through each verified custom link domain and report the domains that do not reach the app.';

    public function __construct(private LinkDomainProbe $probe)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = $this->verified()->count();

        if ($count === 0) {
            $this->info('No verified custom link domains to probe.');

            return self::SUCCESS;
        }

        $this->info("Probing {$count} custom link domain(s)...");

        $unhealthy = 0;

        $this->verified()->chunkById(100, function (Collection $identities) use (&$unhealthy): void {
            foreach ($identities as $identity) {
                $result = $this->probe->check($identity->custom_link_domain);

                if ($result['healthy']) {
                    $this->line("{$identity->custom_link_domain}: healthy.");

                    continue;
                }

                $unhealthy++;
                $reason = $result['reason'] ?? 'The domain does not reach the app.';

                $this->error("{$identity->custom_link_domain}: {$reason}");

                report(LinkDomainUnhealthyException::for($identity, $reason));
            }
        });

        return $unhealthy === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Custom link domains that the app puts into sent mail now.
     *
     * @return Builder<EmailIdentity>
     */
    private function verified(): Builder
    {
        return EmailIdentity::query()
            ->withoutGlobalScopes()
            ->whereNotNull('custom_link_domain')
            ->whereNotNull('custom_link_domain_verified_at');
    }
}
