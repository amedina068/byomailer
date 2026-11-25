<?php

namespace App\Services;

use App\Enums\DkimStatus;
use App\Enums\DnsRecordPurpose;
use App\Enums\IdentityStatus;
use App\Enums\MailFromStatus;
use App\Models\EmailIdentity;

class EmailIdentityStatusSyncService
{
    /**
     * @param  array{success: bool, status?: string, dkim_status?: string|null, mail_from_status?: string|null}  $statusResult
     */
    public function applyAwsStatus(EmailIdentity $identity, array $statusResult): void
    {
        if (! ($statusResult['success'] ?? false)) {
            return;
        }

        $isVerified = ($statusResult['status'] ?? null) === 'verified';

        $identity->update([
            'identity_status' => $isVerified ? IdentityStatus::Verified : IdentityStatus::Verifying,
            'last_status_check_at' => now(),
            'verified_at' => $isVerified ? now() : null,
        ]);

        $identity->refresh();

        $this->syncDnsVerification(
            $identity,
            $statusResult['dkim_status'] ?? null,
            $statusResult['mail_from_status'] ?? null,
        );
    }

    public function syncDnsVerification(EmailIdentity $identity, ?string $dkimStatus, ?string $mailFromStatus): void
    {
        $now = now();

        $dkimVerifiedAt = DkimStatus::tryFrom(strtoupper((string) $dkimStatus)) === DkimStatus::Success ? $now : null;
        $identity->dnsRecords()
            ->where('purpose', DnsRecordPurpose::Dkim)
            ->update(['verified_at' => $dkimVerifiedAt]);

        if ($identity->mail_from_subdomain) {
            $mailFromVerifiedAt = MailFromStatus::tryFrom(strtoupper((string) $mailFromStatus)) === MailFromStatus::Success ? $now : null;

            $identity->dnsRecords()
                ->whereIn('type', ['MX', 'TXT'])
                ->where('name', $identity->mail_from_subdomain)
                ->update(['verified_at' => $mailFromVerifiedAt]);
        }
    }
}
