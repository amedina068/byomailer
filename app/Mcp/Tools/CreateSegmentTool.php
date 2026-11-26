<?php

namespace App\Mcp\Tools;

use App\Actions\CreateSegment;
use App\Concerns\SegmentValidationRules;
use App\Mcp\Concerns\BuildsNewsletterFilters;
use App\Mcp\Concerns\FormatsSegment;
use App\Models\User;
use App\Rules\AudienceFilters;
use App\Services\ContactFilterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a saved segment for the authenticated account. A segment is a named set of 1 to 20 filters in the view-newsletter-filters-tool format. Segment names are unique per account. A duplicate name or an invalid filter returns a validation error and saves nothing. A new segment is not in use, so it sends no mail. Returns the segment with its contacts_count, which includes unsubscribed contacts.')]
class CreateSegmentTool extends Tool
{
    use BuildsNewsletterFilters;
    use FormatsSegment;
    use SegmentValidationRules;

    protected string $name = 'create-segment';

    public function __construct(private readonly ContactFilterService $contactFilterService) {}

    public function handle(Request $request, CreateSegment $createSegment): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $rules = $this->segmentRules($user->client_id);
        $rules['filters'][] = new AudienceFilters;

        $validated = $request->validate([...$this->filterValidationRules(), ...$rules], $this->segmentMessages());

        $segment = $createSegment->handle($user, [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'filters' => $validated['filters'],
        ]);

        return Response::structured([
            ...$this->segmentSummary($segment, $this->contactFilterService),
            'message' => "Segment #{$segment->id} created.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Name of the segment. It must be unique in the account. Maximum 255 characters.')
                ->required(),
            'description' => $schema->string()
                ->description('Optional description of the segment. Maximum 1000 characters.'),
            'filters' => $this->filtersSchema($schema, 'Segment filters, 1 to 20. Use view-newsletter-filters-tool to build the array.')['filters']->required(),
        ];
    }
}
