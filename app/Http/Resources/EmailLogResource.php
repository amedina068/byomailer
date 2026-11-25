<?php

namespace App\Http\Resources;

use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailLog
 */
class EmailLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'operation' => $this->operation,
            'recipient' => $this->recipient,
            'message_id' => $this->message_id,
            'status' => $this->status,
            'error' => $this->error,
            'request_payload' => $this->request_payload,
            'response_payload' => $this->response_payload,
            'sns_payload' => $this->sns_payload,
            'logged_at' => $this->logged_at,
            'created_at' => $this->created_at,
        ];
    }
}
