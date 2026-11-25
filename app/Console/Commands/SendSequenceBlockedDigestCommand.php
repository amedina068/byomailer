<?php

namespace App\Console\Commands;

use App\Mail\SequenceBlockedDigestMail;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\SequenceStep;
use App\Services\SesPermissionChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendSequenceBlockedDigestCommand extends Command
{
    protected $signature = 'ses:send-sequence-blocked-digest';

    protected $description = 'Send a daily digest to users whose sequence emails are blocked by missing SES permissions';

    public function handle(): int
    {
        $credentials = EmailCredential::withoutGlobalScopes()
            ->where('type', 'ses')
            ->whereNotNull('permissions_checked_at')
            ->where('can_send_email', false)
            ->with('user')
            ->get();

        $sent = 0;

        foreach ($credentials as $credential) {
            $user = $credential->user;

            if (! $user) {
                continue;
            }

            $blockedCount = ContactMail::withoutGlobalScopes()
                ->where('client_id', $credential->client_id)
                ->where('status', 'failed')
                ->where('failure_reason', SesPermissionChecker::FAILURE_REASON)
                ->where('mailable_type', SequenceStep::class)
                ->count();

            if ($blockedCount === 0) {
                continue;
            }

            Mail::to($user)->queue(new SequenceBlockedDigestMail($user, $blockedCount));
            $sent++;

            $this->line("  User {$user->id}: {$blockedCount} blocked sequence email(s).");
        }

        $this->info("Sent {$sent} sequence blocked digest(s).");

        return self::SUCCESS;
    }
}
