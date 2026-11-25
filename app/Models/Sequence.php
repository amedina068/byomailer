<?php

namespace App\Models;

use App\Enums\SequenceStatus;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasProjects;
use App\Models\Concerns\ResolvesSegmentFilters;
use App\Services\SequenceStepScheduler;
use App\Support\DetectsTemplateVariables;
use Database\Factories\SequenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property SequenceStatus $status
 */
class Sequence extends Model
{
    /** @use HasFactory<SequenceFactory> */
    use BelongsToClient, DetectsTemplateVariables, HasFactory, HasProjects, ResolvesSegmentFilters;

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
            'status' => SequenceStatus::class,
            'filters' => 'array',
            'content_variables' => 'array',
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

    /**
     * The from name of a step: the name of the identity, or the name of the
     * sequence author when the identity has no name.
     */
    public function senderNameFor(EmailIdentity $identity): ?string
    {
        $author = $this->user;

        return $identity->name ?: ($author instanceof User ? $author->name : null);
    }

    /**
     * Sequences whose membership is governed by audience rules — either
     * non-empty inline filters or a referenced saved segment. Segment-only
     * sequences have empty inline `filters`, so a bare `whereNotNull('filters')`
     * check would silently skip them; this scope keeps both in scope.
     *
     * @param  Builder<Sequence>  $query
     * @return Builder<Sequence>
     */
    public function scopeWithAudienceRules(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where(function (Builder $inner): void {
                $inner->whereNotNull('filters')->where('filters', '!=', '[]');
            })->orWhereNotNull('segment_id');
        });
    }

    /**
     * Count the enrolled contacts and the contacts that finished the sequence.
     *
     * @param  Builder<Sequence>  $query
     * @return Builder<Sequence>
     */
    public function scopeWithContactCounts(Builder $query): Builder
    {
        return $query->withCount([
            'contacts',
            'contacts as completed_count' => fn (Builder $contacts): Builder => $contacts->whereNotNull('contact_sequence.completed_at'),
        ]);
    }

    /**
     * @return HasMany<SequenceStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class)
            ->orderBy(SequenceStepScheduler::delayInHoursExpression())
            ->orderBy('id');
    }

    /**
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)
            ->withPivot('completed_at')
            ->withTimestamps();
    }

    /**
     * @return HasManyThrough<ContactMail, SequenceStep, $this>
     */
    public function contactMails(): HasManyThrough
    {
        return $this->hasManyThrough(
            ContactMail::class,
            SequenceStep::class,
            'sequence_id',
            'mailable_id',
            'id',
            'id',
        )->where('contact_mails.mailable_type', SequenceStep::class);
    }

    /**
     * The {{ variable }} names that the subjects and bodies of the steps use.
     *
     * @return array<int, string>
     */
    public function detectedTemplateVariables(): array
    {
        $this->loadMissing('steps.message');

        return $this->steps
            ->flatMap(fn (SequenceStep $step): array => $step->message === null ? [] : [
                ...$this->detectVariables($step->message->subject ?? ''),
                ...$this->detectVariables($step->message->html_content ?? ''),
            ])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
