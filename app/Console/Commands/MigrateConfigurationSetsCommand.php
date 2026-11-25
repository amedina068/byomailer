<?php

namespace App\Console\Commands;

use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\User;
use Illuminate\Console\Command;

class MigrateConfigurationSetsCommand extends Command
{
    protected $signature = 'ses:migrate-configuration-sets {--user= : Migrate only the client of this user ID} {--dry-run : Show what would be done without making changes}';

    protected $description = 'Migrate SES configuration set data from email credentials to their email identities';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $query = EmailCredential::withoutGlobalScopes()
            ->where('type', 'ses')
            ->whereNotNull('configuration_set_name');

        if ($userId = $this->option('user')) {
            $query->whereIn('client_id', User::query()->select('client_id')->whereKey($userId));
        }

        $credentials = $query->get();

        if ($credentials->isEmpty()) {
            $this->info('No SES credentials with configuration sets found.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Migrating configuration sets for {$credentials->count()} credential(s)...");

        $migrated = 0;
        $skipped = 0;

        foreach ($credentials as $credential) {
            $identities = EmailIdentity::withoutGlobalScopes()
                ->where('client_id', $credential->client_id)
                ->whereNull('configuration_set_name')
                ->get();

            if ($identities->isEmpty()) {
                $this->line("  Client {$credential->client_id}: No identities to migrate (already migrated or none exist).");
                $skipped++;

                continue;
            }

            foreach ($identities as $identity) {
                if ($dryRun) {
                    $this->line("  [DRY RUN] Would copy config set '{$credential->configuration_set_name}' to identity {$identity->domain} (user {$identity->user_id})");
                    $migrated++;

                    continue;
                }

                $identity->update([
                    'configuration_set_name' => $credential->configuration_set_name,
                    'configuration_set_created_at' => $credential->configuration_set_created_at,
                    'open_tracking_enabled' => $credential->open_tracking_enabled,
                    'click_tracking_enabled' => $credential->click_tracking_enabled,
                    'custom_tracking_domain' => $credential->custom_tracking_domain,
                    'custom_tracking_domain_verified_at' => $credential->custom_tracking_domain_verified_at,
                ]);

                $this->line("  Identity {$identity->domain} (user {$identity->user_id}): Copied config set '{$credential->configuration_set_name}'");
                $migrated++;
            }
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '')."Done. Migrated: {$migrated}, Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
