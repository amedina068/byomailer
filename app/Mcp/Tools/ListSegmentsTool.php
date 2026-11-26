<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsSegment;
use App\Models\Segment;
use App\Services\ContactFilterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the saved segments of the authenticated account, newest first. A segment is a saved set of filters in the view-newsletter-filters-tool format. Newsletters and sequences can use a segment as their audience. Each segment includes its id, name, description, filters, archived state and contacts_count. contacts_count includes unsubscribed contacts, the same as the dashboard. newsletters_count and sequences_count tell how many newsletters and sequences use the segment. By default the tool lists only the segments that are not archived. Set status to "archived" or "all" to see the others.')]
class ListSegmentsTool extends Tool
{
    use FormatsSegment;

    protected string $name = 'list-segments';

    private const STATUSES = ['active', 'archived', 'all'];

    public function __construct(private readonly ContactFilterService $contactFilterService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $validated['status'] ?? 'active';
        $search = trim((string) ($validated['search'] ?? ''));

        $segments = Segment::query()
            ->withCount(['newsletters', 'sequences'])
            ->when($status === 'active', fn ($query) => $query->active())
            ->when($status === 'archived', fn ($query) => $query->archived())
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->latest('id')
            ->limit((int) ($validated['limit'] ?? 25))
            ->get()
            ->map(fn (Segment $segment): array => [
                ...$this->segmentSummary($segment, $this->contactFilterService),
                'newsletters_count' => (int) $segment->getAttribute('newsletters_count'),
                'sequences_count' => (int) $segment->getAttribute('sequences_count'),
            ])
            ->all();

        return Response::structured([
            'segments' => $segments,
            'count' => count($segments),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(self::STATUSES)
                ->description('"active" (default) lists the segments that are not archived. "archived" lists the archived segments. "all" lists both.'),
            'search' => $schema->string()
                ->description('Optional search on the segment name. Case does not matter.'),
            'limit' => $schema->integer()
                ->description('Maximum number of segments to return (1-100, default 25).'),
        ];
    }
}
