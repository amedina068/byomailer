<?php

namespace App\Mcp\Tools;

use App\Http\Resources\SequenceResource;
use App\Models\Sequence;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get the full content of a single sequence and every one of its steps: name, status, content variables (the saved default of each {{ variable }}), detected variables (the {{ variables }} that the steps use), and for each step the delay, subject, preview text, html and text body. Find the sequence id with the list-sequences-tool tool.')]
class SequenceContentTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'sequence_id' => ['required', 'integer'],
        ]);

        $sequence = Sequence::query()
            ->with(['steps.message.emailIdentity', 'steps.message.template'])
            ->withCount('contacts')
            ->find($validated['sequence_id']);

        if (! $sequence) {
            return Response::error("No sequence found with id {$validated['sequence_id']}.");
        }

        return Response::structured([
            ...SequenceResource::make($sequence)->resolve(),
            'detected_variables' => $sequence->detectedTemplateVariables(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sequence_id' => $schema->integer()
                ->description('The id of the sequence to read content for.')
                ->required(),
        ];
    }
}
