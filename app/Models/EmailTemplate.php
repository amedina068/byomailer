<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Scopes\ClientScope;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property int|null $client_id
 */
class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use BelongsToClient, HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->withoutGlobalScope(ClientScope::class)->whereNull('client_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function archive(): void
    {
        if ($this->is_default) {
            return;
        }

        $this->forceFill(['archived_at' => now()])->save();
    }

    public function unarchive(): void
    {
        $this->forceFill(['archived_at' => null])->save();
    }

    /**
     * Mark this template as the default for its client, clearing any existing default.
     */
    public function markDefault(): void
    {
        DB::transaction(function (): void {
            static::query()
                ->withoutGlobalScope(ClientScope::class)
                ->where('client_id', $this->client_id)
                ->where('id', '!=', $this->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });
    }

    /**
     * Promote this template to default only if no other default exists for the client.
     */
    public function markDefaultIfNone(): void
    {
        $hasOtherDefault = static::query()
            ->withoutGlobalScope(ClientScope::class)
            ->where('client_id', $this->client_id)
            ->where('id', '!=', $this->id)
            ->where('is_default', true)
            ->exists();

        if (! $hasOtherDefault) {
            $this->markDefault();
        }
    }
}
