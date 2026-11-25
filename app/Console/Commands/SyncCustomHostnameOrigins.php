<?php

namespace App\Console\Commands;

use App\Exceptions\CloudflareException;
use App\Models\EmailIdentity;
use App\Services\CloudflareClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SyncCustomHostnameOrigins extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-identities:sync-hostname-origins';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pin existing Cloudflare custom hostnames to the configured origin server and SNI.';

    public function __construct(private CloudflareClient $cloudflare)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->cloudflare->isConfigured()) {
            $this->error('Cloudflare is not configured.');

            return self::FAILURE;
        }

        if (! $this->cloudflare->hasPinnedOrigin()) {
            $this->error('No origin SNI configured. Set CLOUDFLARE_ORIGIN_SNI first.');

            return self::FAILURE;
        }

        $count = $this->registered()->count();

        if ($count === 0) {
            $this->info('No registered custom hostnames to sync.');

            return self::SUCCESS;
        }

        $this->info("Syncing {$count} custom hostname(s)...");

        $failed = 0;

        $this->registered()->chunkById(100, function ($identities) use (&$failed): void {
            foreach ($identities as $identity) {
                try {
                    $this->cloudflare->syncCustomHostnameOrigin($identity->cf_custom_hostname_id);
                    $this->info("{$identity->custom_link_domain}: origin pinned.");
                } catch (CloudflareException $e) {
                    $failed++;
                    $this->error("{$identity->custom_link_domain}: {$e->getMessage()}");
                }
            }
        });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Identities with a link domain already registered at Cloudflare.
     */
    private function registered(): Builder
    {
        return EmailIdentity::query()
            ->withoutGlobalScopes()
            ->whereNotNull('custom_link_domain')
            ->whereNotNull('cf_custom_hostname_id');
    }
}
