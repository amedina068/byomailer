<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ListsContactMails;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List contact emails for one sequence step with filters, sorting and pagination. Defaults to contacts currently at this step, matching sequence-step-stats-tool; set current_only false to include all historical mails. Find sequence and step ids with list-sequences-tool and sequence-content-tool.')]
class ListSequenceStepContactMailsTool extends Tool
{
    use ListsContactMails;

    public function handle(Request $request): Response|ResponseFactory
    {
        $filters = $this->validateMailFilters($request, stepScoped: true);
        $step = $this->resolveStep($filters);

        if (! $step) {
            return Response::error("No step with id {$filters['step_id']} found on sequence {$filters['sequence_id']}.");
        }

        return $this->mailListResponse($request, $filters, $step);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return $this->mailSchema($schema, stepScoped: true);
    }
}
