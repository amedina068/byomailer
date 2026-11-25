<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Concerns\BelongsToClient;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property WebhookDeliveryStatus $status
 */
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use BelongsToClient, HasFactory, MassPrunable;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $delivery): void {
            if (empty($delivery->uuid)) {
                $delivery->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => WebhookDeliveryStatus::class,
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    /**
     * The scheduled `model:prune` runs with no tenant and prunes every client.
     */
    public function prunable(): Builder
    {
        return static::withoutGlobalScopes()->where('created_at', '<', now()->subDays(config('webhooks.delivery_retention_days')));
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
