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
#[Description('Export all historical contact emails matching the sequence step filters and sorting as CSV, without pagination, including contacts who advanced or completed the sequence. Returns an authenticated download URL; GET it with the same Bearer token used for MCP.')]
class ExportSequenceStepContactMailsTool extends Tool
{
    use ListsContactMails;

    public function handle(Request $request): Response|ResponseFactory
    {
        $filters = $this->validateMailFilters($request, stepScoped: true);
        $step = $this->resolveStep($filters);

        if (! $step) {
            return Response::error("No step with id {$filters['step_id']} found on sequence {$filters['sequence_id']}.");
        }

        unset($filters['page'], $filters['per_page'], $filters['sequence_id'], $filters['step_id'], $filters['current_only']);

        return Response::structured([
            'download_url' => route('api.v1.sequences.steps.contact-mails.export', [
                'sequenceId' => $step->sequence_id,
                'sequenceStepId' => $step->id,
                ...$filters,
            ]),
            'filename' => "sequence-{$step->sequence_id}-step-{$step->id}-contact-mails.csv",
            'authentication' => 'Use the same Bearer token as MCP.',
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $properties = $this->mailSchema($schema, stepScoped: true, paginated: false);
        unset($properties['current_only']);

        return $properties;
    }
}
