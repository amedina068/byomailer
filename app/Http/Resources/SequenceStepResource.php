<?php

namespace App\Http\Resources;

use App\Models\SequenceStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SequenceStep
 */
class SequenceStepResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $message = $this->message;
        $emailIdentity = $message?->emailIdentity;

        return [
            'id' => $this->id,
            'sequence_id' => $this->sequence_id,
            'delay_days' => $this->delay_days,
            'delay_type' => $this->delay_type->value,
            'status' => $this->status->value,
            'filters' => $this->filters ?? [],
            'message' => $message ? [
                'id' => $message->id,
                'subject' => $message->subject,
                'preview_text' => $message->preview_text,
                'html_content' => $message->html_content,
                'text_content' => $message->text_content,
                'email_identity_id' => $message->email_identity_id,
                'email_template_id' => $message->email_template_id,
                'template_slot_content' => $message->template_slot_content,
                'email_template' => $message->template
                    ? EmailTemplateResource::make($message->template)->resolve($request)
                    : null,
                'email_identity' => $emailIdentity
                    ? EmailIdentityResource::make($emailIdentity)->resolve($request)
                    : null,
            ] : null,
            'created_at' => $this->created_at,
        ];
    }
}
