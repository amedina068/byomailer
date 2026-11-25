<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasProjects;
use Database\Factories\AutomationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Automation extends Model
{
    /** @use HasFactory<AutomationFactory> */
    use BelongsToClient, HasFactory, HasProjects;

    const TYPE_SIMPLE = 'simple';

    /**
     * Old rows can have this type. No code runs it, and the validation refuses it.
     */
    const TYPE_MULTISTEP = 'multistep';

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
            'condition' => 'array',
            'actions' => 'array',
            'is_live' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Automation>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('is_live', true);
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
