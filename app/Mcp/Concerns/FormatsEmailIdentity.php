<?php

namespace App\Mcp\Concerns;

use App\Models\EmailIdentity;

trait FormatsEmailIdentity
{
    /**
     * @return array<string, mixed>
     */
    protected function identitySummary(EmailIdentity $identity): array
    {
        return [
            'id' => $identity->id,
            'email' => $identity->email,
            'name' => $identity->name,
            'domain' => $identity->domain,
            'identity_status' => $identity->identity_status->value,
            'dkim_status' => $identity->dkim_status->value,
            'mail_from_status' => $identity->mail_from_status->value,
            'open_tracking_enabled' => (bool) $identity->open_tracking_enabled,
            'click_tracking_enabled' => (bool) $identity->click_tracking_enabled,
            'reply_to_email' => $identity->reply_to_email,
            'reply_to_name' => $identity->reply_to_name,
            'verified_at' => $identity->verified_at?->toIso8601String(),
            'last_status_check_at' => $identity->last_status_check_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function identityDetails(EmailIdentity $identity): array
    {
        return [
            ...$this->identitySummary($identity),
            'mail_from_subdomain' => $identity->mail_from_subdomain,
            'region' => $identity->region,
            'custom_link_domain' => $identity->custom_link_domain,
            'custom_link_domain_verified' => $identity->hasVerifiedLinkDomain(),
            'dns_records' => array_map(fn (array $record): array => [
                'type' => $record['type'],
                'name' => $record['name'],
                'value' => $record['value'],
                'priority' => $record['priority'] ?? null,
                'purpose' => $record['purpose'] ?? null,
                'verified' => isset($record['verified_at']),
            ], $identity->getDnsRecords()),
        ];
    }
}
