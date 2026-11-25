<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContactResource;
use App\Http\Resources\SequenceResource;
use App\Http\Resources\SequenceStepResource;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Services\LinkClickReport;
use App\Services\SequenceAnalyticsService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SequenceStepContactController extends Controller
{
    public function __construct(
        private SequenceAnalyticsService $sequenceAnalytics,
        private LinkClickReport $linkClickReport,
    ) {}

    public function index(Request $request, $sequenceId, $sequenceStepId): Response
    {
        $sequence = Sequence::query()
            ->with(['steps'])
            ->withCount('contacts')
            ->findOrFail($sequenceId);

        $sequenceStep = SequenceStep::query()
            ->where('sequence_id', $sequence->id)
            ->with('message')
            ->findOrFail($sequenceStepId);

        $filter = $this->resolveFilter((string) $request->query('filter'));
        $linkFilter = LinkClickReport::isValidKey($request->query('link')) ? (string) $request->query('link') : null;
        $matchingClicks = $linkFilter === null ? null : $this->linkClickReport->matchingClicks($sequenceStep, $linkFilter);

        $contacts = $this->contactsQuery($request, $sequenceStep, $filter, $matchingClicks)
            ->paginate(20)
            ->withQueryString();

        $clickDetails = $filter === 'clicked' || $matchingClicks !== null
            ? $this->sequenceAnalytics->contactClicks(
                $contacts->getCollection()->pluck('id'),
                collect([$sequenceStep->id]),
                $matchingClicks,
            )
            : [];

        $contacts = $contacts->through(function ($contact) use ($request, $clickDetails) {
            $payload = ContactResource::make($contact)->resolve($request);
            $clicks = $clickDetails[$contact->id] ?? [];
            $payload['latest_click'] = $clicks[0] ?? null;
            $payload['send_date'] = $contact->step_sent_at ?? $contact->step_scheduled_at;
            $payload['send_status'] = $this->deriveSendStatus($contact);
            $payload['step_failure_reason'] = $payload['send_status'] === 'failed' ? $contact->step_failure_reason : null;
            $payload['step_unsubscribed_at'] = $contact->step_unsubscribed_at;

            return $payload;
        });

        $stepIndex = $sequence->steps
            ->pluck('id')
            ->values()
            ->search($sequenceStep->id);

        return Inertia::render('sequences/StepContacts', [
            'sequence' => SequenceResource::make($sequence)->resolve($request),
            'step' => SequenceStepResource::make($sequenceStep)->resolve($request),
            'contacts' => $contacts,
            'filter' => $filter,
            'linkFilter' => $linkFilter,
            'stats' => $this->sequenceAnalytics->statsForStep($sequenceStep),
            'stepIndex' => $stepIndex === false ? null : $stepIndex + 1,
        ]);
    }

    private function contactsQuery(Request $request, SequenceStep $sequenceStep, string $filter, ?Closure $matchingClicks)
    {
        $stepMailColumn = fn (string $column) => ContactMail::query()
            ->select($column)
            ->whereColumn('contact_id', 'contacts.id')
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $sequenceStep->id)
            ->orderByDesc('id')
            ->limit(1);

        return Contact::query()
            ->addSelect([
                'step_status' => $stepMailColumn('status'),
                'step_failure_reason' => $stepMailColumn('failure_reason'),
                'step_scheduled_at' => $stepMailColumn('scheduled_at'),
                'step_sent_at' => $stepMailColumn('sent_at'),
                'step_delivered_at' => $stepMailColumn('delivered_at'),
                'step_opened_at' => $stepMailColumn('opened_at'),
                'step_clicked_at' => $stepMailColumn('clicked_at'),
                'step_bounced_at' => $stepMailColumn('bounced_at'),
                'step_rejected_at' => $stepMailColumn('rejected_at'),
                'step_complained_at' => $stepMailColumn('complained_at'),
                'step_unsubscribed_at' => $stepMailColumn('unsubscribed_at'),
            ])
            ->whereHas('mails', function ($mailQuery) use ($sequenceStep, $filter, $matchingClicks): void {
                $mailQuery->exclusiveToStep($sequenceStep);

                if ($matchingClicks !== null) {
                    $mailQuery->whereHas('clicks', $matchingClicks);
                }

                if ($filter === 'unsubscribed') {
                    $mailQuery->whereNotNull('unsubscribed_at');
                } elseif ($filter === 'clicked') {
                    $mailQuery->whereNotNull('clicked_at');
                }
            })
            ->orderByRaw('COALESCE(step_sent_at, step_scheduled_at) IS NULL')
            ->orderByRaw('COALESCE(step_sent_at, step_scheduled_at) DESC')
            ->orderByDesc('contacts.id');
    }

    private function deriveSendStatus(Contact $contact): string
    {
        return match (true) {
            (bool) $contact->step_unsubscribed_at => 'unsubscribed',
            (bool) $contact->step_complained_at => 'complained',
            (bool) $contact->step_bounced_at => 'bounced',
            (bool) $contact->step_rejected_at => 'rejected',
            (bool) $contact->step_clicked_at => 'clicked',
            (bool) $contact->step_opened_at => 'opened',
            (bool) $contact->step_delivered_at => 'delivered',
            $contact->step_status === 'failed' => 'failed',
            (bool) $contact->step_sent_at => 'sent',
            $contact->step_status === 'skipped' => 'skipped',
            default => 'pending',
        };
    }

    private function resolveFilter(?string $filter): string
    {
        if (in_array($filter, ['unsubscribed', 'clicked'], true)) {
            return $filter;
        }

        return 'all';
    }
}
