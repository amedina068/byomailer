<?php

namespace App\Http\Resources;

use App\Models\ContactImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContactImport
 */
class ContactImportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $progress = $this->total_count > 0
            ? (int) round((($this->processed_count + $this->skipped_count) / $this->total_count) * 100)
            : 0;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'original_filename' => $this->original_filename,
            'total_count' => $this->total_count,
            'processed_count' => $this->processed_count,
            'skipped_count' => $this->skipped_count,
            'progress' => $progress,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at,
        ];
    }
}
