<?php

namespace App\Http\Controllers;

use App\Actions\SubscribeContact;
use App\Actions\UnsubscribeContact;
use App\Actions\UpsertContact;
use App\Http\Requests\StoreContactRequest;
use App\Http\Requests\UpdateContactRequest;
use App\Http\Resources\ContactMailResource;
use App\Http\Resources\ContactResource;
use App\Http\Resources\MailListResource;
use App\Http\Resources\SequenceSummaryResource;
use App\Http\Resources\TagResource;
use App\Models\Contact;
use App\Models\ContactAttribute;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\SequenceStep;
use App\Services\ContactMembership;
use App\Services\ContactReferenceDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function __construct(private ContactReferenceDataService $referenceData) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $listIds = $this->parseIntegerArray($request->input('lists'));
        $tagIds = $this->parseIntegerArray($request->input('tags'));
        $search = $request->input('search', '');

        $contacts = Contact::query()
            ->when(! $request->boolean('include_unsubscribed'), function ($query) {
                $query->whereNull('unsubscribed_at');
            })
            ->when(count($listIds) > 0, function ($query) use ($listIds) {
                $query->whereHas('lists', function ($subQuery) use ($listIds) {
                    $subQuery->whereIn('lists.id', $listIds);
                });
            })
            ->when(count($tagIds) > 0, function ($query) use ($tagIds) {
                $query->whereHas('tags', function ($subQuery) use ($tagIds) {
                    $subQuery->whereIn('tags.id', $tagIds);
                });
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn ($contact) => ContactResource::make($contact)->resolve($request));

        return Inertia::render('contacts/Index', [
            'contacts' => $contacts,
            'lists' => MailListResource::collection($this->referenceData->lists($user))->resolve($request),
            'tags' => TagResource::collection($this->referenceData->tags($user))->resolve($request),
            'sequences' => SequenceSummaryResource::collection($this->referenceData->liveSequences($user))->resolve($request),
            'filters' => [
                'lists' => $listIds,
                'tags' => $tagIds,
                'include_unsubscribed' => $request->boolean('include_unsubscribed'),
                'search' => $search,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('contacts/Create');
    }

    public function store(StoreContactRequest $request, UpsertContact $upsertContact): RedirectResponse
    {
        $contact = $upsertContact->handle($request->user(), $request->validated('email'), [
            'name' => $request->validated('name'),
        ]);

        return to_route('contacts.show', $contact);
    }

    /**
     * @return array<int, int>
     */
    private function parseIntegerArray(mixed $input): array
    {
        if (is_string($input) && $input !== '') {
            return array_filter(array_map('intval', explode(',', $input)), fn ($v) => $v > 0);
        }

        if (is_array($input)) {
            return array_filter(array_map('intval', $input), fn ($v) => $v > 0);
        }

        return [];
    }

    public function show(Request $request, $contactId): Response
    {
        $contact = Contact::query()
            ->with(['tags', 'lists', 'topics.list', 'sequences'])
            ->findOrFail($contactId);
        $suppression = $contact->suppression();

        return Inertia::render('contacts/Show', [
            'contact' => [
                ...ContactResource::make($contact)->resolve($request),
                'sunset_at' => $contact->sunset_at,
            ],
            'suppression' => $suppression ? [
                'reason' => $suppression->reason,
                'can_remove' => $suppression->isRemovable(),
            ] : null,
            'mails' => Inertia::defer(fn () => ContactMailResource::collection($this->contactMails($contact))->resolve($request)),
            'allTags' => TagResource::collection($this->referenceData->tags($request->user()))->resolve($request),
            'allLists' => MailListResource::collection($this->referenceData->lists($request->user()))->resolve($request),
            'allSequences' => SequenceSummaryResource::collection($this->referenceData->liveSequences($request->user()))->resolve($request),
            'contactAttributes' => ContactAttribute::query()
                ->orderBy('name')
                ->pluck('name')
                ->values()
                ->all(),
        ]);
    }

    public function update(UpdateContactRequest $request, $contactId, ContactMembership $membership): RedirectResponse
    {
        $contact = Contact::query()->findOrFail($contactId);

        $membership->persist($request->user(), $contact, $request->validated());

        return back();
    }

    public function unsubscribe(Request $request, $contactId, UnsubscribeContact $unsubscribeContact): RedirectResponse
    {
        $unsubscribeContact->handle($request->user(), Contact::query()->findOrFail($contactId));

        return back();
    }

    public function subscribe(Request $request, $contactId, SubscribeContact $subscribeContact): RedirectResponse
    {
        $request->validate(['remove_suppression' => ['sometimes', 'boolean']]);

        $subscribeContact->handle($request->user(), Contact::query()->findOrFail($contactId), $request->boolean('remove_suppression'));

        return back();
    }

    private function contactMails(Contact $contact)
    {
        return ContactMail::query()
            ->where('contact_id', $contact->id)
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->with([
                'mailable' => function ($query): void {
                    $query->morphWith([
                        Newsletter::class => ['message'],
                        SequenceStep::class => ['sequence', 'message'],
                    ]);
                },
            ])
            ->take(15)
            ->get();
    }
}
