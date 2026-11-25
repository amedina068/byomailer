<?php

namespace App\Mcp\Tools;

use App\Enums\SequenceStatus;
use App\Mcp\Concerns\BuildsNewsletterFilters;
use App\Mcp\Concerns\HandlesContentVariables;
use App\Models\User;
use App\Services\FilterPreparationService;
use App\Support\ContentVariables;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a draft sequence (automated drip campaign) for the authenticated account. Sequences are always created as drafts. Activate a sequence with set-sequence-status after the user confirms. Pass filters to limit which contacts the sequence targets (use view-newsletter-filters-tool to build them). Pass content_variables to set the default value of each {{ variable }} that the steps use, for example a fallback checkout_url. Add steps with create-sequence-step-tool and edit the sequence later with update-sequence-tool. Returns the new sequence id, status, saved filters and content variables.')]
class CreateSequenceTool extends Tool
{
    use BuildsNewsletterFilters;
    use HandlesContentVariables;

    public function __construct(private readonly FilterPreparationService $filterPreparationService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            ...$this->filterValidationRules(),
            ...ContentVariables::rules(),
        ]);

        $sequence = $user->sequences()->create([
            'name' => $validated['name'],
            'status' => SequenceStatus::Draft,
            'filters' => $this->filterPreparationService->prepareFilters($validated['filters'] ?? [], $user),
            'content_variables' => ContentVariables::normalize($validated['content_variables'] ?? []),
        ]);

        return Response::structured([
            'id' => $sequence->id,
            'name' => $sequence->name,
            'status' => $sequence->status->value,
            'filters' => $sequence->filters,
            'content_variables' => $sequence->content_variables,
            'message' => "Draft sequence #{$sequence->id} created. Add steps with create-sequence-step-tool.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Name for the sequence.')
                ->required(),
            ...$this->filtersSchema($schema, 'Sequence filters. Only contacts who match them receive the sequence. Use view-newsletter-filters-tool to build the array. Omit to target every active contact.'),
            'content_variables' => $this->contentVariablesSchema($schema),
        ];
    }
}
