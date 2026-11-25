<?php

namespace App\Console\Commands;

use App\Exceptions\AwsCredentialsException;
use App\Models\EmailCredential;
use App\Services\AwsCredentialValidator;
use App\Services\SesCredentials;
use App\Services\SesTenantService;
use Illuminate\Console\Command;

class BackfillSesTenantsCommand extends Command
{
    protected $signature = 'ses:backfill-tenants {--dry-run : Show what would be done without making changes}';

    protected $description = 'Create SES tenants for existing credentials and associate their identities';

    public function __construct(
        private SesTenantService $tenantService,
        private AwsCredentialValidator $credentialValidator,
        private SesCredentials $sesCredentials,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $credentials = EmailCredential::withoutGlobalScopes()
            ->where('type', 'ses')
            ->whereNull('tenant_name')
            ->with('client')
            ->get();

        $alreadyTenanted = EmailCredential::withoutGlobalScopes()
            ->where('type', 'ses')
            ->whereNotNull('tenant_name')
            ->count();

        if ($credentials->isEmpty()) {
            $this->info("No SES credentials without tenants found. ({$alreadyTenanted} already tenanted)");

            return self::SUCCESS;
        }

        $this->line("Skipping {$alreadyTenanted} credential(s) that already have a tenant.");

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Processing {$credentials->count()} credential(s)...");

        $created = 0;
        $failed = 0;

        foreach ($credentials as $credential) {
            $this->line("Processing credential {$credential->id} (user {$credential->user_id}, client {$credential->client_id}, region {$credential->region})...");

            try {
                $aws = $this->sesCredentials->for($credential);
            } catch (AwsCredentialsException $exception) {
                $this->warn("  {$exception->getMessage()} Skipping.");
                $failed++;

                continue;
            }

            if (! $credential->aws_account_id) {
                if ($dryRun) {
                    $this->line('  [DRY RUN] Would fetch account ID via STS.');
                } else {
                    $this->line('  Fetching AWS account ID...');
                    $accountId = $this->credentialValidator->fetchAccountId(
                        $aws->getAccessKeyId(),
                        $aws->getSecretKey(),
                        $credential->region,
                        $aws->getSecurityToken()
                    );

                    if (! $accountId) {
                        $this->warn('  Failed to fetch account ID, skipping.');
                        $failed++;

                        continue;
                    }

                    $credential->update(['aws_account_id' => $accountId]);
                }
            }

            $tenantName = 'byomailer';

            if ($dryRun) {
                $this->line("  [DRY RUN] Would create tenant '{$tenantName}'.");
            } else {
                $result = $this->tenantService->provisionTenant(
                    $tenantName,
                    $aws->getAccessKeyId(),
                    $aws->getSecretKey(),
                    $credential->region,
                    $aws->getSecurityToken()
                );

                if (! $result['success']) {
                    $this->warn('  Failed to create tenant: '.($result['error'] ?? 'Unknown error'));
                    $failed++;

                    continue;
                }

                $this->line('  Tenant provisioned. ARN: '.($result['tenant_arn'] ?? 'N/A'));

                $credential->update(['tenant_name' => $tenantName]);
            }

            $identities = $credential->client->emailIdentities()
                ->withoutGlobalScopes()
                ->whereNotNull('configuration_set_name')
                ->whereNull('tenant_associated_at')
                ->get();

            $this->line("  Found {$identities->count()} unassociated identities.");

            foreach ($identities as $identity) {
                $identityArn = SesTenantService::buildIdentityArn($credential->region, $credential->aws_account_id, $identity->domain);
                $configSetArn = SesTenantService::buildConfigurationSetArn($credential->region, $credential->aws_account_id, $identity->configuration_set_name);
                $this->line("  Processing identity {$identity->id} ({$identity->domain}):");
                $this->line("    identity_arn={$identityArn}");
                $this->line("    config_set_arn={$configSetArn}");
                $this->line("    aws_account_id={$credential->aws_account_id}");
                if ($dryRun) {
                    $this->line("  [DRY RUN] Would associate identity {$identity->id} ({$identity->domain}).");

                    continue;
                }

                $result = $this->tenantService->associateIdentityWithTenant(
                    $tenantName,
                    $credential->region,
                    $credential->aws_account_id,
                    $identity->domain,
                    $identity->configuration_set_name,
                    $aws->getAccessKeyId(),
                    $aws->getSecretKey(),
                    $aws->getSecurityToken(),
                );

                if (! $result['success']) {
                    $this->warn("  Failed to associate identity {$identity->domain}: ".($result['error'] ?? 'Unknown'));

                    continue;
                }

                $identity->update(['tenant_associated_at' => now()]);
                $this->line("  Associated identity {$identity->domain} with tenant.");
            }

            $created++;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Done. Created: {$created}, Failed: {$failed}.");

        return self::SUCCESS;
    }
}
