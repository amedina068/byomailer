<?php

namespace App\Mcp\Tools;

use App\Actions\SetSegmentArchived;
use App\Mcp\Concerns\FormatsSegment;
use App\Models\Segment;
use App\Services\ContactFilterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Archive or restore a saved segment of the authenticated account. Set archived to true to archive the segment, or to false to restore it. An archived segment is not in the dashboard pickers and not in the default list-segments result. Newsletters and sequences that use the segment keep its filters, so this change sends no mail and stops no mail. It needs no confirmation. Find segment ids with list-segments.')]
class SetSegmentArchivedTool extends Tool
{
    use FormatsSegment;

    protected string $name = 'set-segment-archived';

    public function __construct(private readonly ContactFilterService $contactFilterService) {}

    public function handle(Request $request, SetSegmentArchived $setSegmentArchived): Response|ResponseFactory
    {
        $validated = $request->validate([
            'segment_id' => ['required', 'integer'],
            'archived' => ['required', 'boolean'],
        ]);

        $segment = Segment::query()->find($validated['segment_id']);

        if ($segment === null) {
            return Response::error("No segment found with id {$validated['segment_id']}.");
        }

        $archived = (bool) $validated['archived'];

        if ($segment->isArchived() === $archived) {
            return Response::structured([
                ...$this->segmentSummary($segment, $this->contactFilterService),
                'applied' => false,
                'message' => "Segment #{$segment->id} is already ".($archived ? 'archived' : 'active').'. Nothing changed.',
            ]);
        }

        $setSegmentArchived->handle($segment, $archived);

        return Response::structured([
            ...$this->segmentSummary($segment, $this->contactFilterService),
            'applied' => true,
            'message' => "Segment #{$segment->id} ".($archived ? 'archived' : 'restored').'.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'segment_id' => $schema->integer()
                ->description('Id of the segment. Use list-segments to find segment ids.')
                ->required(),
            'archived' => $schema->boolean()
                ->description('true archives the segment. false restores it.')
                ->required(),
        ];
    }
}
