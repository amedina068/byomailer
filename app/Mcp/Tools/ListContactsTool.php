<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsNewsletterFilters;
use App\Models\Contact;
use App\Models\User;
use App\Services\ContactFilterService;
use App\Services\FilterPreparationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the contacts of the authenticated account, oldest id first. Filter with "filters" in the view-newsletter-filters-tool format (tags, lists, topics, attributes, dates, engagement, location) and with "search" on the name or email. Unsubscribed contacts are excluded unless include_unsubscribed is true. Each contact includes its tag names, list ids and custom attributes. To read the next page, send next_after_id from the result as after_id. The cursor stays correct when you change the listed contacts between pages, so use this tool to collect the ids for bulk-update-contacts.')]
class ListContactsTool extends Tool
{
    use BuildsNewsletterFilters;

    protected string $name = 'list-contacts';

    public function __construct(
        private readonly FilterPreparationService $filterPreparationService,
        private readonly ContactFilterService $contactFilterService,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            ...$this->filterValidationRules(),
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'include_unsubscribed' => ['sometimes', 'boolean'],
            'after_id' => ['sometimes', 'integer', 'min:0'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $filters = $this->filterPreparationService->prepareFilters($validated['filters'] ?? [], $user);
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = $this->contactFilterService->segmentContactsQuery($user->client_id, $filters)
            ->when(! ($validated['include_unsubscribed'] ?? false), fn ($query) => $query->whereNull('unsubscribed_at'))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")));

        $total = (clone $query)->count();

        $contacts = $query
            ->when(isset($validated['after_id']), fn ($query) => $query->where('id', '>', $validated['after_id']))
            ->with(['tags:tags.id,tags.name', 'lists:lists.id'])
            ->orderBy('id')
            ->limit($perPage + 1)
            ->get(['id', 'email', 'name', 'extra_attributes', 'unsubscribed_at', 'created_at']);

        $hasMore = $contacts->count() > $perPage;
        $contacts = $contacts->take($perPage);

        return Response::structured([
            'contacts' => $contacts->map(fn (Contact $contact): array => [
                'id' => $contact->id,
                'email' => $contact->email,
                'name' => $contact->name,
                'unsubscribed_at' => $contact->unsubscribed_at?->toIso8601String(),
                'tags' => $contact->tags->pluck('name')->values()->all(),
                'lists' => $contact->lists->pluck('id')->values()->all(),
                'attributes' => (object) ($contact->extra_attributes ?? []),
                'created_at' => $contact->created_at?->toIso8601String(),
            ])->values()->all(),
            'total' => $total,
            'has_more' => $hasMore,
            'next_after_id' => $hasMore ? $contacts->last()?->id : null,
            'applied_filters' => $filters,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->filtersSchema($schema, 'Audience filters in the view-newsletter-filters-tool format. Omit to list every contact.'),
            'search' => $schema->string()
                ->description('Optional case-insensitive search on the contact name or email.'),
            'include_unsubscribed' => $schema->boolean()
                ->description('Include unsubscribed contacts. Default false.'),
            'after_id' => $schema->integer()
                ->description('Return only contacts with an id above this value. Send next_after_id from the previous page.'),
            'per_page' => $schema->integer()
                ->description('Page size (1-100, default 25).'),
        ];
    }
}
