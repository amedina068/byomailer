<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Support\Filters\FilterSchema;
use Database\Factories\SegmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @phpstan-import-type AudienceFilter from FilterSchema
 *
 * @property int $id
 * @property int $client_id
 * @property string $name
 * @property string|null $description
 * @property array<int, AudienceFilter> $filters
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Segment extends Model
{
    /** @use HasFactory<SegmentFactory> */
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
            'filters' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<Segment>  $query
     * @return Builder<Segment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<Segment>  $query
     * @return Builder<Segment>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Active segments plus the one currently selected (even if archived), so an
     * editor that already targets an archived segment still renders it.
     *
     * @param  Builder<Segment>  $query
     * @return Builder<Segment>
     */
    public function scopeActiveOrSelected(Builder $query, ?int $selectedId): Builder
    {
        return $query->where(fn (Builder $inner): Builder => $inner->active()->orWhere('id', $selectedId));
    }

    /**
     * @return HasMany<Newsletter, $this>
     */
    public function newsletters(): HasMany
    {
        return $this->hasMany(Newsletter::class);
    }

    /**
     * @return HasMany<Sequence, $this>
     */
    public function sequences(): HasMany
    {
        return $this->hasMany(Sequence::class);
    }
}
