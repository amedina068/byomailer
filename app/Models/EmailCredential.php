<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Database\Factories\EmailCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCredential extends Model
{
    /** @use HasFactory<EmailCredentialFactory> */
    use BelongsToClient, HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * The keys are secrets. They never go into an array or a JSON response.
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_key',
        'secret_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_key' => 'encrypted',
            'secret_key' => 'encrypted',
            'configuration_set_created_at' => 'datetime',
            'sns_subscription_confirmed_at' => 'datetime',
            'open_tracking_enabled' => 'boolean',
            'click_tracking_enabled' => 'boolean',
            'can_create_template' => 'boolean',
            'can_delete_template' => 'boolean',
            'can_send_bulk_email' => 'boolean',
            'can_send_email' => 'boolean',
            'permissions_checked_at' => 'datetime',
            'permissions_alert_count' => 'integer',
            'permissions_alerted_at' => 'datetime',
            'production_access_enabled' => 'boolean',
            'sending_enabled' => 'boolean',
            'max_24_hour_send' => 'integer',
            'max_send_rate' => 'integer',
            'sent_last_24_hours' => 'integer',
            'quota_fetched_at' => 'datetime',
            'custom_tracking_domain_verified_at' => 'datetime',
        ];
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
}
