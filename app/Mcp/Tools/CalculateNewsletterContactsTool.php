<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsNewsletterFilters;
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
#[Description('Count how many contacts would receive a newsletter sent to the given audience filters, without creating or sending anything. Pass the same "filters" array you would use with create-newsletter-tool (omit it to count every active contact). Use view-newsletter-filters-tool to discover valid filter values. Returns the recipient count and the sanitized filters that were applied.')]
class CalculateNewsletterContactsTool extends Tool
{
    use BuildsNewsletterFilters;

    public function __construct(
        private readonly FilterPreparationService $filterPreparationService,
        private readonly ContactFilterService $contactFilterService,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate($this->filterValidationRules());

        /** @var User $user */
        $user = $request->user();

        $filters = $this->filterPreparationService->prepareFilters(
            $validated['filters'] ?? [],
            $user,
        );

        $count = $this->contactFilterService
            ->newsletterRecipientsQuery($user->client_id, $filters)
            ->count();

        return Response::structured([
            'count' => $count,
            'applied_filters' => $filters,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->filtersSchema($schema);
    }
}
