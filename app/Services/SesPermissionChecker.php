<?php

namespace App\Services;

use App\Exceptions\MissingSesPermissionException;
use App\Models\EmailCredential;
use App\Models\User;

class SesPermissionChecker
{
    public const FAILURE_REASON = 'ses_permission_missing';

    public const NEWSLETTER_BULK = [
        'can_create_template' => 'ses:CreateEmailTemplate',
        'can_delete_template' => 'ses:DeleteEmailTemplate',
        'can_send_bulk_email' => 'ses:SendBulkEmail',
    ];

    public const SEND_EMAIL = [
        'can_send_email' => 'ses:SendEmail',
    ];

    /**
     * Get missing permissions for a set of required capabilities.
     *
     * @param  array<string, string>  $required  Map of credential field => SES action name
     * @return array<int, string> List of missing SES action names
     */
    public function getMissing(User $user, array $required, ?EmailCredential $credential = null): array
    {
        $credential ??= $user->sesCredential;

        if (! $this->hasBeenChecked($credential)) {
            return [];
        }

        $missing = [];

        foreach ($required as $field => $action) {
            if (! $credential->{$field}) {
                $missing[] = $action;
            }
        }

        return $missing;
    }

    /**
     * Check if a user has all the given permissions.
     *
     * @param  array<string, string>  $required
     */
    public function hasAll(User $user, array $required): bool
    {
        return empty($this->getMissing($user, $required));
    }

    /**
     * Throw if any required permissions are missing.
     *
     * @param  array<string, string>  $required
     *
     * @throws MissingSesPermissionException
     */
    public function ensureAll(User $user, array $required): void
    {
        $missing = $this->getMissing($user, $required);

        if (! empty($missing)) {
            throw new MissingSesPermissionException($missing, $user->id);
        }
    }

    private function hasBeenChecked(?EmailCredential $credential): bool
    {
        return $credential !== null && $credential->permissions_checked_at !== null;
    }
}
