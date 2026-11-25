<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSegmentRequest;
use App\Http\Requests\UpdateSegmentRequest;
use App\Http\Resources\ContactResource;
use App\Http\Resources\MailListResource;
use App\Http\Resources\SegmentResource;
use App\Http\Resources\TagResource;
use App\Http\Resources\TopicResource;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\Topic;
use App\Services\ContactFilterService;
use App\Services\FilterPreparationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SegmentController extends Controller
{
    public function __construct(
        private FilterPreparationService $filterPreparationService,
        private ContactFilterService $contactFilterService,
    ) {}

    public function index(Request $request): Response
    {
        $showArchived = $request->boolean('archived');

        $segments = Segment::query()
            ->when($showArchived, fn ($query) => $query->archived(), fn ($query) => $query->active())
            ->latest('id')
            ->get();

        return Inertia::render('segments/Index', [
            'segments' => SegmentResource::collection($segments)->resolve($request),
            'counts' => Inertia::defer(fn (): array => $this->contactCounts($segments)),
            'showingArchived' => $showArchived,
            'activeCount' => Segment::query()->active()->count(),
            'archivedCount' => Segment::query()->archived()->count(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('segments/Create', $this->filterFormOptions($request));
    }

    public function store(StoreSegmentRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $request->user()->client->segments()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'filters' => $this->filterPreparationService->prepareFilters($data['filters'] ?? [], $request->user()),
        ]);

        return to_route('segments.index')->with('success', 'Segment created.');
    }

    public function show(Request $request, Segment $segment): Response
    {
        $filters = $segment->filters ?? [];

        $contacts = $this->contactFilterService
            ->segmentContactsQuery($segment->client_id, $filters)
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Contact $contact): array => ContactResource::make($contact)->resolve($request));

        return Inertia::render('segments/Show', [
            'segment' => SegmentResource::make($segment)->resolve($request),
            'contacts' => $contacts,
            'matchingCount' => $contacts->total(),
            'eligibleCount' => $this->contactFilterService
                ->newsletterRecipientsQuery($segment->client_id, $filters)
                ->count(),
            'usedBy' => $this->usedBy($segment),
            ...$this->filterSentenceContext($request),
        ]);
    }

    public function edit(Request $request, Segment $segment): Response
    {
        return Inertia::render('segments/Edit', [
            'segment' => SegmentResource::make($segment)->resolve($request),
            'usedBy' => $this->usedBy($segment),
            ...$this->filterFormOptions($request),
        ]);
    }

    public function update(UpdateSegmentRequest $request, Segment $segment): RedirectResponse
    {
        $data = $request->validated();

        $segment->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'filters' => $this->filterPreparationService->prepareFilters($data['filters'] ?? [], $request->user()),
        ]);

        return to_route('segments.show', $segment)->with('success', 'Segment updated.');
    }

    /**
     * @param  Collection<int, Segment>  $segments
     * @return array<int, int>
     */
    private function contactCounts(Collection $segments): array
    {
        return $segments
            ->mapWithKeys(fn (Segment $segment): array => [
                $segment->id => $this->contactFilterService
                    ->segmentContactsQuery($segment->client_id, $segment->filters ?? [])
                    ->count(),
            ])
            ->all();
    }

    /**
     * @return array{newsletters: array<int, array{id: int, title: string}>, sequences: array<int, array{id: int, name: string}>}
     */
    private function usedBy(Segment $segment): array
    {
        return [
            'newsletters' => $segment->newsletters()
                ->get(['id', 'title'])
                ->map(fn ($newsletter): array => ['id' => $newsletter->id, 'title' => $newsletter->title])
                ->all(),
            'sequences' => $segment->sequences()
                ->get(['id', 'name'])
                ->map(fn ($sequence): array => ['id' => $sequence->id, 'name' => $sequence->name])
                ->all(),
        ];
    }

    /**
     * Picker data + sentence-rendering context shared by the filter builder.
     *
     * @return array<string, mixed>
     */
    private function filterFormOptions(Request $request): array
    {
        $client = $request->user()->client;

        $attributeKeys = $client->contactAttributes()
            ->orderBy('name')
            ->pluck('name')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $options = $this->filterPreparationService->filterOptionData($client->id);

        return [
            ...$this->filterSentenceContext($request),
            'tags' => TagResource::collection($client->tags()->orderBy('name')->get())->resolve($request),
            'attributeKeys' => $attributeKeys,
            'filterCountries' => $options['countries'],
            'filterTimezones' => $options['timezones'],
        ];
    }

    /**
     * The lists / topics / newsletters needed to render filter sentences.
     *
     * @return array{lists: array<int, mixed>, topics: array<int, mixed>, filterNewsletters: array<int, array{id: int, title: string}>}
     */
    private function filterSentenceContext(Request $request): array
    {
        $client = $request->user()->client;

        return [
            'lists' => MailListResource::collection($client->mailingLists()->orderBy('name')->get())->resolve($request),
            'topics' => TopicResource::collection(Topic::query()->with(['list:id,name'])->orderBy('name')->get())->resolve($request),
            'filterNewsletters' => $this->filterPreparationService->filterOptionData($client->id)['newsletters'],
        ];
    }
}
