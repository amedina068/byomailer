<?php

namespace App\Http\Resources;

use App\Models\EmailCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin EmailCredential
 */
class EmailCredentialResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $accessKey = (string) $this->access_key;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'region' => $this->region,
            'access_key_hint' => $accessKey !== '' ? Str::substr($accessKey, -4) : null,
            'can_create_template' => $this->can_create_template,
            'can_delete_template' => $this->can_delete_template,
            'can_send_bulk_email' => $this->can_send_bulk_email,
            'can_send_email' => $this->can_send_email,
            'permissions_checked_at' => $this->permissions_checked_at?->toIso8601String(),
            'production_access_enabled' => $this->production_access_enabled,
            'sending_enabled' => $this->sending_enabled,
            'max_24_hour_send' => $this->max_24_hour_send,
            'max_send_rate' => $this->max_send_rate,
            'sent_last_24_hours' => $this->sent_last_24_hours,
            'quota_fetched_at' => $this->quota_fetched_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
