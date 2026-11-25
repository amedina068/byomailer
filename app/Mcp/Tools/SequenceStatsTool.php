<?php

namespace App\Mcp\Tools;

use App\Http\Resources\SequenceStatsResource;
use App\Models\Sequence;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get the sequence-level stats for a single sequence: contact, unsubscribe and click totals plus aggregate mail metrics (sent/delivered/opened/bounced rates) across all steps. For a per-step breakdown use the sequence-step-stats-tool tool. Find the sequence id with the list-sequences-tool tool.')]
class SequenceStatsTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'sequence_id' => ['required', 'integer'],
        ]);

        $sequence = Sequence::query()
            ->with('steps')
            ->withCount('contacts')
            ->find($validated['sequence_id']);

        if (! $sequence) {
            return Response::error("No sequence found with id {$validated['sequence_id']}.");
        }

        return Response::structured(SequenceStatsResource::make($sequence)->resolve());
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sequence_id' => $schema->integer()
                ->description('The id of the sequence to read stats for.')
                ->required(),
        ];
    }
}
