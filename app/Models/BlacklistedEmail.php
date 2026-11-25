<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Database\Factories\BlacklistedEmailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlacklistedEmail extends Model
{
    /** @use HasFactory<BlacklistedEmailFactory> */
    use BelongsToClient, HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * A client can remove its own row of a bounce, because the address can
     * work again. A complaint and a global row stay.
     */
    public function isRemovable(): bool
    {
        return $this->client_id !== null && in_array($this->reason, ['bounce', 'soft_bounce'], true);
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
