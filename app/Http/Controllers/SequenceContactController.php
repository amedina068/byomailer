<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContactResource;
use App\Http\Resources\SequenceResource;
use App\Models\Contact;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Services\SequenceAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SequenceContactController extends Controller
{
    public function __construct(private SequenceAnalyticsService $sequenceAnalytics) {}

    public function index(Request $request, $sequenceId): Response
    {
        $sequence = Sequence::query()
            ->with(['steps'])
            ->withCount('contacts')
            ->findOrFail($sequenceId);

        $filter = $this->resolveFilter((string) $request->query('filter'));

        $contacts = $this->contactsQuery($request, $sequence, $filter)
            ->paginate(20)
            ->withQueryString();

        $clickDetails = $filter === 'clicked'
            ? $this->sequenceAnalytics->contactClicks(
                $contacts->getCollection()->pluck('id'),
                $sequence->steps->pluck('id'),
            )
            : [];

        $contacts = $contacts->through(function ($contact) use ($request, $clickDetails) {
            $payload = ContactResource::make($contact)->resolve($request);
            $clicks = $clickDetails[$contact->id] ?? [];
            $payload['latest_click'] = $clicks[0] ?? null;

            return $payload;
        });

        return Inertia::render('sequences/Contacts', [
            'sequence' => SequenceResource::make($sequence)->resolve($request),
            'contacts' => $contacts,
            'filter' => $filter,
            'stats' => $this->sequenceAnalytics->sequenceStats($sequence),
        ]);
    }

    private function contactsQuery(Request $request, Sequence $sequence, string $filter)
    {
        $query = Contact::query()
            ->orderBy('name')
            ->orderBy('id');

        if ($filter === 'all') {
            return $query->whereHas('sequences', fn ($builder) => $builder->where('sequences.id', $sequence->id));
        }

        if ($filter === 'completed') {
            return $query->whereHas('sequences', fn ($builder) => $builder
                ->where('sequences.id', $sequence->id)
                ->whereNotNull('contact_sequence.completed_at'));
        }

        $stepIds = $sequence->steps->pluck('id')->filter();

        return $query->whereHas('mails', function ($mailQuery) use ($filter, $stepIds): void {
            $mailQuery
                ->where('mailable_type', SequenceStep::class)
                ->whereIn('mailable_id', $stepIds);

            if ($filter === 'unsubscribed') {
                $mailQuery->whereNotNull('unsubscribed_at');
            } else {
                $mailQuery->whereNotNull('clicked_at');
            }
        });
    }

    private function resolveFilter(?string $filter): string
    {
        if (in_array($filter, ['unsubscribed', 'clicked', 'completed'], true)) {
            return $filter;
        }

        return 'all';
    }
}
