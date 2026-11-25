<?php

namespace App\Http\Resources;

use App\Models\Sequence;
use App\Services\SequenceAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sequence
 */
class SequenceStatsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The sequence must be loaded with its `steps` and a `contacts_count` so the
     * analytics service can aggregate sequence-level totals. Per-step stats are
     * served separately by SequenceStepStatsResource.
     *
     * @return array{
     *   id: int,
     *   name: string,
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
            'name' => $this->name,
            'status' => $this->status->value,
            'stats' => $analytics->sequenceStats($this->resource),
            'mail_metrics' => $analytics->mailMetricsForSequence($this->resource),
        ];
    }
}
