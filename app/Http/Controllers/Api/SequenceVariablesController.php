<?php

namespace App\Http\Controllers\Api;

use App\Actions\UpdateSequence;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateContentVariablesRequest;
use App\Models\Sequence;
use App\Support\ContentVariables;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Sequences', 'A sequence is a series of emails. Each enrolled contact gets the steps on a schedule. The API reads sequences, steps and statistics, exports the mails of a step, and changes step filters and content variables.', weight: 6)]
class SequenceVariablesController extends Controller
{
    /**
     * Get the content variables of a sequence.
     *
     * Returns the `{{ variables }}` that the steps of the sequence use, and the
     * saved default of each one. A default fills a variable when the contact has
     * no value for it.
     */
    public function show(int $sequenceId): JsonResponse
    {
        $sequence = Sequence::query()->findOrFail($sequenceId);

        return $this->variablesResponse($sequence);
    }

    /**
     * Replace the content variables of a sequence.
     *
     * Replaces the content variable defaults of the sequence. The object replaces
     * all saved defaults. A default can name a variable that no step uses yet.
     */
    public function update(UpdateContentVariablesRequest $request, int $sequenceId, UpdateSequence $updateSequence): JsonResponse
    {
        $sequence = Sequence::query()->findOrFail($sequenceId);

        $updateSequence->handle($request->user(), $sequence, [
            'content_variables' => $request->validated('content_variables'),
        ]);

        return $this->variablesResponse($sequence);
    }

    private function variablesResponse(Sequence $sequence): JsonResponse
    {
        $detected = $sequence->detectedTemplateVariables();

        return response()->json([
            'data' => [
                'sequence_id' => $sequence->id,
                'detected_variables' => $detected,
                /** @var array<string, string> */
                'content_variables' => ContentVariables::withDetected($detected, $sequence->content_variables),
            ],
        ]);
    }
}
