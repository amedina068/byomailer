<?php

namespace App\Http\Resources;

use App\Models\SequenceStep;
use App\Services\SequenceAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SequenceStep
 */
class SequenceStepStatsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The step must be loaded with its `message` so the subject is available.
     * Stats and mail metrics are scoped to contacts whose furthest progress is
     * this step.
     *
     * @return array{
     *   id: int,
     *   sequence_id: int,
     *   subject: string|null,
     *   delay_days: int|null,
     *   delay_type: string|null,
     *   status: string,
     *   stats: array{contacts: int, unsubscribed: int, clicked: int},
     *   mail_metrics: array{total: int, sent: int, delivered: int, pending: int, skipped: int, opened: int, bounced: int, unsubscribed: int, complained: int, delivery_rate: float|null, open_rate: float|null, unsubscribe_rate: float|null}
     * }
     */
    public function toArray(Request $request): array
    {
        $analytics = app(SequenceAnalyticsService::class);

        return [
            'id' => $this->id,
            'sequence_id' => $this->sequence_id,
            'subject' => $this->message?->subject,
            'delay_days' => $this->delay_days,
            'delay_type' => $this->delay_type->value,
            'status' => $this->status->value,
            'stats' => $analytics->statsForStep($this->resource),
            'mail_metrics' => $analytics->mailMetricsForStep($this->resource),
        ];
    }
}
