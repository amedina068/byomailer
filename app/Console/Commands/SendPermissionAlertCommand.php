<?php

namespace App\Console\Commands;

use App\Mail\SesPermissionAlertMail;
use App\Models\EmailCredential;
use App\Services\SesPermissionChecker;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendPermissionAlertCommand extends Command
{
    protected $signature = 'ses:send-permission-alerts';

    protected $description = 'Notify users when their SES credentials are missing required permissions';

    public function handle(SesPermissionChecker $checker): int
    {
        $credentials = EmailCredential::withoutGlobalScopes()
            ->where('type', 'ses')
            ->whereNotNull('permissions_checked_at')
            ->with('user')
            ->get();

        $alerted = 0;

        foreach ($credentials as $credential) {
            $alerted += Tenant::run($credential->client_id, fn (): int => $this->alertWhenPermissionsAreMissing($checker, $credential));
        }

        $this->info("Sent {$alerted} permission alert(s).");

        return self::SUCCESS;
    }

    /**
     * Check one credential. Return 1 when an alert was sent, else 0.
     */
    private function alertWhenPermissionsAreMissing(SesPermissionChecker $checker, EmailCredential $credential): int
    {
        $user = $credential->user;

        if (! $user) {
            return 0;
        }

        $allRequired = array_merge(
            SesPermissionChecker::NEWSLETTER_BULK,
            SesPermissionChecker::SEND_EMAIL,
        );

        $missing = $checker->getMissing($user, $allRequired);

        if (empty($missing)) {
            $this->resetAlertState($credential);

            return 0;
        }

        $credential->increment('permissions_alert_count');

        if (! $this->shouldAlert($credential)) {
            return 0;
        }

        Mail::to($user)->queue(new SesPermissionAlertMail($user, $missing));

        $credential->update(['permissions_alerted_at' => now()]);

        return 1;
    }

    private function shouldAlert(EmailCredential $credential): bool
    {
        $count = $credential->permissions_alert_count;

        if ($count === 1 || $count === 6) {
            return true;
        }

        if ($count > 6 && $credential->permissions_alerted_at) {
            return $credential->permissions_alerted_at->diffInHours(now()) >= 12;
        }

        return false;
    }

    private function resetAlertState(EmailCredential $credential): void
    {
        if ($credential->permissions_alert_count > 0 || $credential->permissions_alerted_at !== null) {
            $credential->update([
                'permissions_alert_count' => 0,
                'permissions_alerted_at' => null,
            ]);
        }
    }
}
