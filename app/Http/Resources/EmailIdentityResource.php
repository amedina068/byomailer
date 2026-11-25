<?php

namespace App\Http\Resources;

use App\Models\EmailIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailIdentity
 */
class EmailIdentityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'email' => $this->email,
            'original_email' => $this->original_email,
            'name' => $this->name,
            'domain' => $this->domain,
            'mail_from_subdomain' => $this->mail_from_subdomain,
            'identity_status' => $this->identity_status->value,
            'dkim_status' => $this->dkim_status->value,
            'mail_from_status' => $this->mail_from_status->value,
            'dns_records' => $this->getDnsRecords(),
            'verified_at' => $this->verified_at,
            'last_status_check_at' => $this->last_status_check_at,
            'open_tracking_enabled' => $this->open_tracking_enabled,
            'click_tracking_enabled' => $this->click_tracking_enabled,
            'reply_to_email' => $this->reply_to_email,
            'reply_to_name' => $this->reply_to_name,
            'custom_tracking_domain' => $this->custom_tracking_domain,
            'custom_tracking_domain_verified_at' => $this->custom_tracking_domain_verified_at?->toIso8601String(),
            'custom_tracking_a_record_ip' => config('services.tracking_proxy.ip'),
            'custom_link_domain' => $this->custom_link_domain,
            'custom_link_domain_verified_at' => $this->custom_link_domain_verified_at?->toIso8601String(),
            'custom_link_cname_target' => config('services.cloudflare.cname_target'),
            'has_configuration_set' => $this->configuration_set_name !== null,
            'tenant_isolated' => $this->tenant_associated_at !== null,
        ];
    }
}
