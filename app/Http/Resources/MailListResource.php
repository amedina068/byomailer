<?php

namespace App\Http\Resources;

use App\Models\MailList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MailList
 */
class MailListResource extends JsonResource
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
            'name' => $this->name,
            'opt_in_enabled' => $this->opt_in_enabled,
            'message' => $this->when($this->relationLoaded('message') && $this->message, fn () => [
                'subject' => $this->message->subject,
                'html_content' => $this->message->html_content,
                'email_identity_id' => $this->message->email_identity_id,
            ]),
            'contacts_count' => $this->when(isset($this->contacts_count), fn () => $this->contacts_count),
            'topics_count' => $this->when(isset($this->topics_count), fn () => $this->topics_count),
            'created_at' => $this->created_at,
        ];
    }
}
