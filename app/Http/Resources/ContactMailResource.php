<?php

namespace App\Http\Resources;

use App\Models\ContactMail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContactMail
 */
class ContactMailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $message = $this->mailable->message;

        return [
            'id' => $this->id,
            'subject' => $this->subject ?? $message->subject,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at,
            'sent_at' => $this->sent_at,
            'bounced_at' => $this->bounced_at,
            'delivered_at' => $this->delivered_at,
            'opened_at' => $this->opened_at,
            'complained_at' => $this->complained_at,
            'rejected_at' => $this->rejected_at,
            'unsubscribed_at' => $this->unsubscribed_at,
            'from_email' => $this->from_email,
            'from_name' => $this->from_name,
            'html_content' => $message?->html_content,
            'text_content' => $message?->text_content,
            'failure_reason' => $this->failure_reason,
            'template_data' => $this->template_data ?? [],
            'newsletter' => new NewsletterResource($this->whenLoaded('newsletter')),
            'sequence_step' => new SequenceStepResource($this->whenLoaded('sequenceStep')),
        ];
    }
}
