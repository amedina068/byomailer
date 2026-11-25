<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ContactMailQuery
{
    public const STATUSES = ['pending', 'sent', 'delivered', 'opened', 'bounced', 'rejected', 'complained', 'unsubscribed', 'failed', 'skipped'];

    public const FILTERS = ['all', 'pending', 'sent', 'delivered', 'opened', 'clicked', 'bounced', 'rejected', 'complained', 'unsubscribed', 'failed', 'skipped'];

    public const DATE_COLUMNS = ['scheduled_at', 'sent_at', 'delivered_at', 'opened_at', 'clicked_at', 'bounced_at', 'rejected_at', 'complained_at', 'unsubscribed_at', 'created_at'];

    public const SORT_COLUMNS = ['id', 'email', 'name', 'subject', 'status', ...self::DATE_COLUMNS];

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'contact_id' => ['sometimes', 'integer', 'min:1'],
            'newsletter_id' => ['sometimes', 'integer', 'min:1'],
            'sequence_id' => ['sometimes', 'integer', 'min:1'],
            'step_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', self::STATUSES)],
            'filter' => ['sometimes', 'string', 'in:'.implode(',', self::FILTERS)],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sent_after' => ['sometimes', 'date'],
            'sent_before' => ['sometimes', 'date', 'after_or_equal:sent_after'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORT_COLUMNS)],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ContactMail>
     */
    public function forUser(User $user, array $filters, ?SequenceStep $step = null, bool $currentOnly = false): Builder
    {
        $query = ContactMail::query()
            ->where('contact_mails.client_id', $user->client_id)
            ->whereHas('contact', fn (Builder $query): Builder => $query->where('client_id', $user->client_id))
            ->whereHasMorph('mailable', [Newsletter::class, SequenceStep::class], function (Builder $query, string $type) use ($user): void {
                if ($type === SequenceStep::class) {
                    $query->whereHas('sequence', fn (Builder $query): Builder => $query->where('client_id', $user->client_id));
                } else {
                    $query->where('client_id', $user->client_id);
                }
            })
            ->with('contact');

        if ($step) {
            if ($currentOnly) {
                $query->exclusiveToStep($step);
            } else {
                $query->where('mailable_type', SequenceStep::class)->where('mailable_id', $step->id);
            }
        }

        foreach (['contact_id', 'status'] as $column) {
            if (isset($filters[$column])) {
                $query->where('contact_mails.'.$column, $filters[$column]);
            }
        }

        if (isset($filters['newsletter_id'])) {
            $query->where('mailable_type', Newsletter::class)->where('mailable_id', $filters['newsletter_id']);
        }

        if (isset($filters['sequence_id']) || isset($filters['step_id'])) {
            $query->where('mailable_type', SequenceStep::class)
                ->whereHasMorph('mailable', [SequenceStep::class], function (Builder $query) use ($filters): void {
                    if (isset($filters['sequence_id'])) {
                        $query->where('sequence_id', $filters['sequence_id']);
                    }
                    if (isset($filters['step_id'])) {
                        $query->where('id', $filters['step_id']);
                    }
                });
        }

        $filter = $filters['filter'] ?? 'all';
        if (in_array($filter, ['pending', 'failed', 'skipped'], true)) {
            $query->where('contact_mails.status', $filter);
        } elseif ($filter !== 'all') {
            $query->whereNotNull('contact_mails.'.$filter.'_at');
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('contact_mails.subject', 'like', "%{$search}%")
                    ->orWhereHas('contact', function (Builder $query) use ($search): void {
                        $query->where('email', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        foreach (['sent_after' => '>=', 'sent_before' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $query->where('contact_mails.sent_at', $operator, Carbon::parse($filters[$key])->utc());
            }
        }

        $sort = $filters['sort'] ?? 'id';
        $direction = $filters['direction'] ?? 'desc';

        if (in_array($sort, ['email', 'name'], true)) {
            $query->orderBy(Contact::query()->select($sort)->whereColumn('contacts.id', 'contact_mails.contact_id'), $direction);
        } else {
            $query->orderBy('contact_mails.'.$sort, $direction);
        }

        if ($sort !== 'id') {
            $query->orderBy('contact_mails.id', $direction);
        }

        return $query;
    }
}
