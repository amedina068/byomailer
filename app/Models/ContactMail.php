<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUuid;
use App\Services\SequenceStepScheduler;
use Database\Factories\ContactMailFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\URL;

class ContactMail extends Model
{
    /** @use HasFactory<ContactMailFactory> */
    use BelongsToClient, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! $model->client_id && $model->contact_id) {
                $model->client_id = Contact::withoutGlobalScopes()
                    ->where('id', $model->contact_id)
                    ->value('client_id');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'bounced_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'rejected_at' => 'datetime',
            'complained_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'reserved_at' => 'datetime',
            'template_data' => 'array',
        ];
    }

    protected function unsubscribeLink(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->linkDomainUrl('unsubscribe'));
    }

    /**
     * The RFC 8058 one-click endpoint advertised in the `List-Unsubscribe`
     * header. It is a separate path from `unsubscribe_link` because mailbox
     * providers POST to it without a CSRF token, so it is exempt from CSRF
     * verification — the human-facing page must not share that exemption.
     */
    protected function oneClickUnsubscribeLink(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->linkDomainUrl('one-click-unsubscribe'));
    }

    /**
     * The double opt-in confirmation link of an opt-in email.
     */
    protected function optInLink(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->linkDomainUrl('opt-in'));
    }

    /**
     * Build a plain contact-facing link on the sending identity's verified
     * link domain, falling back to the canonical host. Every unsigned link in
     * a sent email goes through here, so the header URL and the body URL can
     * never end up on different hosts.
     */
    private function linkDomainUrl(string $path): ?string
    {
        if (! $this->uuid) {
            return null;
        }

        $host = $this->linkDomainHost();

        if ($host) {
            return 'https://'.$host.'/'.$path.'/'.$this->uuid;
        }

        return url('/'.$path.'/'.$this->uuid);
    }

    protected function preferencesLink(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (! $this->uuid) {
                return null;
            }

            $host = $this->linkDomainHost();

            if (! $host) {
                return URL::signedRoute('preferences.show', ['contactMail' => $this->uuid]);
            }

            URL::forceRootUrl('https://'.$host);
            URL::forceScheme('https');

            try {
                return URL::signedRoute('preferences.show', ['contactMail' => $this->uuid]);
            } finally {
                URL::forceRootUrl(null);
                URL::forceScheme(null);
            }
        });
    }

    /**
     * The sending identity's verified custom link domain, when one is set.
     * Falls back to null so callers use the canonical app host.
     */
    public function linkDomainHost(): ?string
    {
        $identity = $this->resolveSendingIdentity();

        return $identity && $identity->hasVerifiedLinkDomain()
            ? $identity->custom_link_domain
            : null;
    }

    private function resolveSendingIdentity(): ?EmailIdentity
    {
        $message = $this->relationLoaded('message')
            ? $this->message
            : ($this->message_id ? Message::withoutGlobalScopes()->find($this->message_id) : null);

        if (! $message?->email_identity_id) {
            return null;
        }

        if ($message->relationLoaded('emailIdentity')) {
            return $message->emailIdentity;
        }

        return EmailIdentity::withoutGlobalScopes()->find($message->email_identity_id);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function mailable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<ContactMailClick, $this>
     */
    public function clicks(): HasMany
    {
        return $this->hasMany(ContactMailClick::class);
    }

    protected function newsletter(): Attribute
    {
        return Attribute::get(function (): ?Newsletter {
            if ($this->relationLoaded('mailable') && $this->mailable instanceof Newsletter) {
                return $this->mailable;
            }

            if ($this->mailable_type === Newsletter::class) {
                return $this->mailable;
            }

            return null;
        });
    }

    protected function sequenceStep(): Attribute
    {
        return Attribute::get(function (): ?SequenceStep {
            if ($this->relationLoaded('mailable') && $this->mailable instanceof SequenceStep) {
                return $this->mailable;
            }

            if ($this->mailable_type === SequenceStep::class) {
                return $this->mailable;
            }

            return null;
        });
    }

    protected function newsletterId(): Attribute
    {
        return Attribute::get(function (): ?int {
            return $this->mailable_type === Newsletter::class ? $this->mailable_id : null;
        });
    }

    protected function sequenceStepId(): Attribute
    {
        return Attribute::get(function (): ?int {
            return $this->mailable_type === SequenceStep::class ? $this->mailable_id : null;
        });
    }

    /**
     * Scope mails to a single sequence step, excluding contacts who have
     * progressed to any later step in the same sequence or who have already
     * finished the sequence. Used to count or list contacts "currently at"
     * this step — a finished contact no longer lingers in the final step.
     */
    public function scopeExclusiveToStep(Builder $query, SequenceStep $step): Builder
    {
        $laterStepIds = SequenceStepScheduler::laterStepIdsFor($step);

        return $query
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $step->id)
            ->whereNotExists(function (QueryBuilder $sub) use ($step): void {
                $sub->from('contact_sequence')
                    ->whereColumn('contact_sequence.contact_id', 'contact_mails.contact_id')
                    ->where('contact_sequence.sequence_id', $step->sequence_id)
                    ->whereNotNull('contact_sequence.completed_at');
            })
            ->when($laterStepIds->isNotEmpty(), function (Builder $q) use ($laterStepIds): void {
                $q->whereNotIn('contact_id', ContactMail::query()
                    ->select('contact_id')
                    ->where('mailable_type', SequenceStep::class)
                    ->whereIn('mailable_id', $laterStepIds));
            });
    }
}
