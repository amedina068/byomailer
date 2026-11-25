<?php

namespace App\Models;

use App\Enums\AbVariant;
use App\Enums\NewsletterStatus;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasProjects;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ResolvesSegmentFilters;
use App\Support\DetectsTemplateVariables;
use App\Support\Tenant;
use Database\Factories\NewsletterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $client_id
 * @property int|null $segment_id
 * @property string $title
 * @property NewsletterStatus $status
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $sent_at
 * @property array<int, array<string, mixed>> $filters
 * @property array<string, string|null>|null $content_variables
 * @property bool $ab_test_enabled
 * @property AbVariant|null $ab_test_winner_variant
 * @property int $total_contacts_reached
 * @property int|null $last_dispatched_contact_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Newsletter extends Model
{
    /** @use HasFactory<NewsletterFactory> */
    use BelongsToClient, DetectsTemplateVariables, HasFactory, HasProjects, HasUuid, ResolvesSegmentFilters;

    /**
     * The title of a new draft in every channel.
     */
    public const DEFAULT_TITLE = 'Untitled newsletter';

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
            'status' => NewsletterStatus::class,
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'filters' => 'array',
            'content_variables' => 'array',
            'ab_test_enabled' => 'boolean',
            'ab_test_winner_variant' => AbVariant::class,
            'total_contacts_reached' => 'integer',
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
     * @return MorphMany<Message, $this>
     */
    public function messages(): MorphMany
    {
        return $this->morphMany(Message::class, 'messageable');
    }

    /**
     * @return MorphOne<Message, $this>
     */
    public function message(): MorphOne
    {
        return $this->morphOne(Message::class, 'messageable');
    }

    /**
     * @return MorphOne<Message, $this>
     */
    public function messageA(): MorphOne
    {
        return $this->morphOne(Message::class, 'messageable')->where('variant', AbVariant::A);
    }

    /**
     * @return MorphOne<Message, $this>
     */
    public function messageB(): MorphOne
    {
        return $this->morphOne(Message::class, 'messageable')->where('variant', AbVariant::B);
    }

    /**
     * @return HasOneThrough<EmailIdentity, Message, $this>
     */
    public function emailIdentity(): HasOneThrough
    {
        return $this->hasOneThrough(
            EmailIdentity::class,
            Message::class,
            'messageable_id',
            'id',
            'id',
            'email_identity_id'
        )->where('messages.messageable_type', $this->getMorphClass());
    }

    /**
     * @return MorphMany<ContactMail, $this>
     */
    public function contactMails(): MorphMany
    {
        return $this->morphMany(ContactMail::class, 'mailable');
    }

    public function isMutable(): bool
    {
        return $this->status->isMutable();
    }

    /**
     * @param  Builder<Newsletter>  $query
     */
    public function scopeMutable(Builder $query): void
    {
        $query->whereIn('status', NewsletterStatus::mutable());
    }

    /**
     * @param  int|array<int, int>|null  $only  Restrict the aggregate to specific newsletter ids.
     */
    public function scopeWithMetricsCounts(Builder $query, int|array|null $only = null): Builder
    {
        $counts = DB::table('contact_mails')
            ->selectRaw('mailable_id')
            ->selectRaw('COUNT(*) as contact_mails_count')
            ->selectRaw('SUM(CASE WHEN sent_at IS NOT NULL OR delivered_at IS NOT NULL THEN 1 ELSE 0 END) as sent_count')
            ->selectRaw('SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count', ['pending'])
            ->selectRaw('SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened_count')
            ->selectRaw('SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked_count')
            ->selectRaw('SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced_count')
            ->selectRaw('SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) as unsubscribed_count')
            ->selectRaw('SUM(CASE WHEN complained_at IS NOT NULL THEN 1 ELSE 0 END) as complained_count')
            ->where('mailable_type', self::class)
            ->when(Tenant::id(), fn (QueryBuilder $q, int $clientId) => $q->where('client_id', $clientId))
            ->when($only !== null, fn (QueryBuilder $q) => $q->whereIn('mailable_id', (array) $only))
            ->groupBy('mailable_id');

        return $query
            ->leftJoinSub($counts, 'nm', 'nm.mailable_id', '=', 'newsletters.id')
            ->addSelect('newsletters.*')
            ->selectRaw('COALESCE(nm.contact_mails_count, 0) as contact_mails_count')
            ->selectRaw('COALESCE(nm.sent_count, 0) as sent_count')
            ->selectRaw('COALESCE(nm.delivered_count, 0) as delivered_count')
            ->selectRaw('COALESCE(nm.pending_count, 0) as pending_count')
            ->selectRaw('COALESCE(nm.opened_count, 0) as opened_count')
            ->selectRaw('COALESCE(nm.clicked_count, 0) as clicked_count')
            ->selectRaw('COALESCE(nm.bounced_count, 0) as bounced_count')
            ->selectRaw('COALESCE(nm.unsubscribed_count, 0) as unsubscribed_count')
            ->selectRaw('COALESCE(nm.complained_count, 0) as complained_count');
    }

    /**
     * @return array<string, callable|string>
     */
    public static function metricsCountDefinitions(): array
    {
        return [
            'contactMails',
            'contactMails as sent_count' => fn (Builder $q): Builder => $q
                ->where(function (Builder $inner): void {
                    $inner->whereNotNull('sent_at')->orWhereNotNull('delivered_at');
                }),
            'contactMails as delivered_count' => fn (Builder $q): Builder => $q->whereNotNull('delivered_at'),
            'contactMails as pending_count' => fn (Builder $q): Builder => $q->where('status', 'pending'),
            'contactMails as opened_count' => fn (Builder $q): Builder => $q->whereNotNull('opened_at'),
            'contactMails as clicked_count' => fn (Builder $q): Builder => $q->whereNotNull('clicked_at'),
            'contactMails as bounced_count' => fn (Builder $q): Builder => $q->whereNotNull('bounced_at'),
            'contactMails as unsubscribed_count' => fn (Builder $q): Builder => $q->whereNotNull('unsubscribed_at'),
            'contactMails as complained_count' => fn (Builder $q): Builder => $q->whereNotNull('complained_at'),
        ];
    }

    /**
     * The {{ variable }} names that the subject and body use, of variant B too
     * when the A/B test is on.
     *
     * @return array<int, string>
     */
    public function detectedTemplateVariables(): array
    {
        $this->loadMissing(['message', 'messageB']);

        $messages = $this->ab_test_enabled ? [$this->message, $this->messageB] : [$this->message];

        return collect($messages)
            ->filter()
            ->flatMap(fn (Message $message): array => [
                ...$this->detectVariables($message->subject ?? ''),
                ...$this->detectVariables($message->body()),
            ])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
