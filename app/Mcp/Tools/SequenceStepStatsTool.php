<?php

namespace App\Mcp\Tools;

use App\Http\Resources\SequenceStepStatsResource;
use App\Models\Sequence;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get the stats for a single sequence step: contacts reached, unsubscribe and click totals, plus mail metrics (sent/delivered/opened/bounced rates) for contacts whose furthest progress is this step. Find the sequence and step ids with the list-sequences-tool and sequence-content-tool tools.')]
class SequenceStepStatsTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'sequence_id' => ['required', 'integer'],
            'step_id' => ['required', 'integer'],
        ]);

        $sequence = Sequence::query()
            ->with('steps.message')
            ->find($validated['sequence_id']);

        if (! $sequence) {
            return Response::error("No sequence found with id {$validated['sequence_id']}.");
        }

        $step = $sequence->steps->firstWhere('id', $validated['step_id']);

        if (! $step) {
            return Response::error("No step with id {$validated['step_id']} found on sequence {$validated['sequence_id']}.");
        }

        return Response::structured(SequenceStepStatsResource::make($step)->resolve());
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sequence_id' => $schema->integer()
                ->description('The id of the sequence the step belongs to.')
                ->required(),
            'step_id' => $schema->integer()
                ->description('The id of the sequence step to read stats for.')
                ->required(),
        ];
    }
}
