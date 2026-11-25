<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateSequence;
use App\Enums\SequenceStatus;
use App\Mcp\Concerns\BuildsNewsletterFilters;
use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Mcp\Concerns\HandlesContentVariables;
use App\Models\Sequence;
use App\Models\User;
use App\Support\ContentVariables;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a sequence of any status. A live sequence sends to real contacts now, so the tool refuses the change until you ask the user to confirm and call it again with confirm_live set to true. Draft and paused sequences need no confirmation. The status never changes here: use set-sequence-status. Only the fields you pass are changed; omitted fields keep their current values. Pass filters to replace the sequence filters (an empty array clears them). Filters and a saved segment are exclusive, so passing filters removes a saved segment from the sequence. Pass content_variables to replace the default values of the {{ variables }} (an empty object clears them). Find sequence ids with list-sequences-tool and read the current filters and content variables with sequence-content-tool. Returns the sequence id, status, saved filters and content variables.')]
class UpdateSequenceTool extends Tool
{
    use BuildsNewsletterFilters;
    use ConfirmsLiveEdits;
    use HandlesContentVariables;

    public function handle(Request $request, UpdateSequence $updateSequence): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'sequence_id' => ['required', 'integer'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            ...$this->filterValidationRules(),
            ...ContentVariables::rules(),
            ...$this->confirmLiveValidationRules(),
        ]);

        $sequence = Sequence::query()->find($validated['sequence_id']);

        if ($sequence === null) {
            return Response::error("No sequence found with id {$validated['sequence_id']}.");
        }

        if ($sequence->status === SequenceStatus::Live) {
            $refusal = $this->liveEditNeedsConfirmation($validated, "Sequence #{$sequence->id}", 'Its contacts receive its steps now. New filters change which contacts get the next steps, and new content variables change the next emails.');

            if ($refusal !== null) {
                return $refusal;
            }
        }

        $refusal = DB::transaction(function () use ($user, $sequence, $validated, $updateSequence): ?Response {
            $changed = $this->statusChangedSinceCheck($sequence, 'status', "Sequence #{$sequence->id}");

            if ($changed !== null) {
                return $changed;
            }

            $updateSequence->handle($user, $sequence, array_intersect_key(
                $validated,
                array_flip(['name', 'filters', 'content_variables']),
            ));

            return null;
        });

        if ($refusal !== null) {
            return $refusal;
        }

        return Response::structured([
            'id' => $sequence->id,
            'name' => $sequence->name,
            'status' => $sequence->status->value,
            'filters' => $sequence->filters ?? [],
            'content_variables' => $sequence->content_variables ?? [],
            'message' => $sequence->status->label()." sequence #{$sequence->id} updated.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sequence_id' => $schema->integer()
                ->description('Id of the sequence to update. Use list-sequences-tool to discover sequence ids.')
                ->required(),
            'name' => $schema->string()->description('New name for the sequence.'),
            ...$this->filtersSchema($schema, 'Sequence filters. Only contacts who match them receive the sequence. Use view-newsletter-filters-tool to build the array. The array replaces the current filters. Pass an empty array to clear them.'),
            'content_variables' => $this->contentVariablesSchema($schema),
            ...$this->confirmLiveSchema($schema),
        ];
    }
}
