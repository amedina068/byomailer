<?php

namespace App\Http\Resources;

use App\Models\ContactMail;
use App\Services\ContactMailQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContactMail
 */
class ContactMailSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'contact' => $this->contact ? [
                'id' => $this->contact->id,
                'email' => $this->contact->email,
                'name' => $this->contact->name,
            ] : null,
            'newsletter_id' => $this->newsletter_id,
            'sequence_step_id' => $this->sequence_step_id,
            'subject' => $this->subject,
            'status' => $this->status,
            'from_email' => $this->from_email,
            'from_name' => $this->from_name,
            'failure_reason' => $this->failure_reason,
        ];

        foreach (ContactMailQuery::DATE_COLUMNS as $column) {
            $payload[$column] = $this->{$column}?->toIso8601String();
        }

        return $payload;
    }
}
