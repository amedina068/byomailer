<?php

namespace App\Console\Commands;

use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\SesClientManager;
use App\Services\SesConfigurationSetService;
use App\Services\SesPermissionChecker;
use Illuminate\Console\Command;
use Throwable;

class SyncConfigurationSetsCommand extends Command
{
    protected $signature = 'ses:sync-configuration-sets {--user= : Sync for a specific user ID}';

    protected $description = 'Sync SES configuration set tracking settings and API permissions from the API to the database';

    public function handle(SesConfigurationSetService $configService, SesClientManager $clientManager): int
    {
        $credentials = EmailCredential::withoutGlobalScopes()
            ->where('type', 'ses')
            ->when($this->option('user'), fn ($query, $userId) => $query->where(
                'client_id',
                User::query()->whereKey($userId)->value('client_id'),
            ))
            ->with('user')
            ->get();

        if ($credentials->isEmpty()) {
            $this->info('No users with SES credentials found.');

            return self::SUCCESS;
        }

        $this->info("Syncing configuration sets for {$credentials->count()} user(s)...");

        $success = 0;
        $failed = 0;

        foreach ($credentials as $credential) {
            $user = $credential->user;
            $user->setRelation('sesCredential', $credential);

            try {
                $hadSendPermission = $credential->can_send_email;

                $this->syncIdentityTrackingSettings($user, $credential, $configService);
                $this->syncPermissions($user, $clientManager);

                $credential->refresh();

                if (! $hadSendPermission && $credential->can_send_email) {
                    $this->requeueBlockedSequenceMails($user, $credential);
                }

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

    private function syncIdentityTrackingSettings(
        User $user,
        EmailCredential $credential,
        SesConfigurationSetService $configService
    ): void {
        $identities = EmailIdentity::withoutGlobalScopes()
            ->where('client_id', $credential->client_id)
            ->whereNotNull('configuration_set_name')
            ->get();

        if ($identities->isEmpty()) {
            $this->line("  User {$user->id}: No identities with configuration sets, skipping tracking sync.");

            return;
        }

        foreach ($identities as $identity) {
            $result = $configService->fetchTrackingStatus(
                $identity->configuration_set_name,
                $credential->access_key,
                $credential->secret_key,
                $identity->region
            );

            if (! $result['success']) {
                $this->warn("  Identity {$identity->domain} (user {$user->id}): Tracking sync failed - {$result['error']}");

                continue;
            }

            $identity->update([
                'open_tracking_enabled' => $result['open_tracking_enabled'],
                'click_tracking_enabled' => $result['click_tracking_enabled'],
            ]);

            $openStatus = $result['open_tracking_enabled'] ? 'ON' : 'OFF';
            $clickStatus = $result['click_tracking_enabled'] ? 'ON' : 'OFF';
            $this->line("  Identity {$identity->domain} (user {$user->id}): Open tracking {$openStatus}, Click tracking {$clickStatus}");
        }
    }

    private function requeueBlockedSequenceMails(User $user, EmailCredential $credential): void
    {
        $requeued = ContactMail::withoutGlobalScopes()
            ->where('client_id', $credential->client_id)
            ->where('status', 'failed')
            ->where('failure_reason', SesPermissionChecker::FAILURE_REASON)
            ->where('mailable_type', SequenceStep::class)
            ->update([
                'status' => 'pending',
                'failure_reason' => null,
            ]);

        if ($requeued > 0) {
            $this->line("  User {$user->id}: Requeued {$requeued} blocked sequence email(s).");
        }
    }

    private function syncPermissions(
        User $user,
        SesClientManager $clientManager
    ): void {
        $permissions = $clientManager->fetchAndUpdatePermissions($user);

        $statuses = collect($permissions)
            ->map(fn ($v, $k) => str_replace('can_', '', $k).': '.($v ? 'YES' : 'NO'))
            ->implode(', ');

        $this->line("  User {$user->id}: Permissions - {$statuses}");
    }
}
