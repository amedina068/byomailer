<?php

namespace App\Models;

use App\Enums\DelayUnit;
use App\Enums\SequenceStatus;
use Database\Factories\SequenceStepFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @property DelayUnit $delay_type
 * @property SequenceStatus $status
 */
class SequenceStep extends Model
{
    /** @use HasFactory<SequenceStepFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * The database default of `delay_type`. A new step reads it before a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'delay_type' => 'days',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delay_days' => 'integer',
            'delay_type' => DelayUnit::class,
            'status' => SequenceStatus::class,
            'filters' => 'array',
        ];
    }

    /**
     * Steps of the current client. A step has no client_id, so the
     * ClientScope of its sequence limits the steps.
     *
     * @param  Builder<SequenceStep>  $query
     */
    public function scopeOfCurrentClient(Builder $query): void
    {
        $query->whereHas('sequence');
    }

    /**
     * @return BelongsTo<Sequence, $this>
     */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    /**
     * @return MorphOne<Message, $this>
     */
    public function message(): MorphOne
    {
        return $this->morphOne(Message::class, 'messageable');
    }

    /**
     * @return MorphMany<ContactMail, $this>
     */
    public function contactMails(): MorphMany
    {
        return $this->morphMany(ContactMail::class, 'mailable');
    }
}
