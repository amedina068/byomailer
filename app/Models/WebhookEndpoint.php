<?php

namespace App\Models;

use App\Enums\WebhookDisabledReason;
use App\Models\Concerns\BelongsToClient;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property WebhookDisabledReason|null $disabled_reason
 */
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use BelongsToClient, HasFactory;

    public const ADMIN_ONLY_MESSAGE = 'Only an admin of the account can manage webhook endpoints.';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $endpoint): void {
            if (empty($endpoint->uuid)) {
                $endpoint->uuid = (string) Str::uuid();
            }

            if (empty($endpoint->secret)) {
                $endpoint->secret = static::generateSecret();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
            'last_triggered_at' => 'datetime',
            'disabled_at' => 'datetime',
            'disabled_reason' => WebhookDisabledReason::class,
        ];
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }

    public function activate(): void
    {
        $this->forceFill([
            'active' => true,
            'consecutive_failures' => 0,
            'disabled_at' => null,
            'disabled_reason' => null,
        ])->save();
    }

    public function deactivate(WebhookDisabledReason $reason = WebhookDisabledReason::Manual): void
    {
        $this->forceFill([
            'active' => false,
            'disabled_at' => now(),
            'disabled_reason' => $reason,
        ])->save();
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
