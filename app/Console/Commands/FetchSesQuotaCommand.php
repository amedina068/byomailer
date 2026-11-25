<?php

namespace App\Console\Commands;

use App\Models\EmailCredential;
use App\Models\User;
use App\Services\SesClientManager;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Throwable;

class FetchSesQuotaCommand extends Command
{
    protected $signature = 'ses:fetch-quota {--user= : Fetch the quota of the client of this user ID}';

    protected $description = 'Fetch SES sending quota for all users with credentials';

    public function handle(SesClientManager $clientManager): int
    {
        $query = EmailCredential::withoutGlobalScopes()
            ->where('type', 'ses')
            ->with('user');

        if ($userId = $this->option('user')) {
            $query->whereIn('client_id', User::query()->select('client_id')->whereKey($userId));
        }

        $credentials = $query->get();

        if ($credentials->isEmpty()) {
            $this->info('No SES credentials found.');

            return self::SUCCESS;
        }

        $this->info("Fetching SES quota for {$credentials->count()} credential(s)...");

        $success = 0;
        $failed = 0;

        foreach ($credentials as $credential) {
            $user = $credential->user;

            if (! $user) {
                $this->warn("Credential {$credential->id} has no associated user.");
                $failed++;

                continue;
            }

            try {
                $quota = Tenant::run($credential->client_id, fn (): array => $clientManager->fetchAndUpdateQuota($user));

                $status = $quota['production_access_enabled'] ? 'Production' : 'Sandbox';
                $this->line("  User {$user->id}: {$status} - {$quota['sent_last_24_hours']}/{$quota['max_24_hour_send']} sent, {$quota['max_send_rate']}/sec rate");

                $success++;
            } catch (Throwable $e) {
                $this->error("  User {$user->id}: Failed - {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done. Success: {$success}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
