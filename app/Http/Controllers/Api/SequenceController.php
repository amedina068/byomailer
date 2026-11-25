<?php

namespace App\Http\Controllers\Api;

use App\Enums\SequenceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SequenceResource;
use App\Http\Resources\SequenceSummaryResource;
use App\Models\Sequence;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Sequences', 'A sequence is a series of emails. Each enrolled contact gets the steps on a schedule. The API reads sequences, steps and statistics, exports the mails of a step, and changes step filters and content variables.', weight: 6)]
class SequenceController extends Controller
{
    /**
     * List sequences.
     *
     * Returns the sequences of your client. Live sequences come first, then the
     * others by name. Each item has its number of steps and contacts.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            /** The sequence status. `paused` is the same as `draft`: a paused sequence has the status `draft`. */
            'status' => ['sometimes', 'string', Rule::in(SequenceStatus::inputValues())],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'nullable', 'string'],
        ]);

        $search = trim((string) $request->input('search', ''));

        $sequences = Sequence::query()
            ->with('projects:projects.id')
            ->withCount('steps')
            ->withContactCounts()
            ->when($request->filled('status'), fn ($query) => $query->where('status', SequenceStatus::fromInput((string) $request->input('status'))))
            ->when($request->filled('project'), fn ($query) => $query->whereHas(
                'projects',
                fn ($projectQuery) => $projectQuery->where('projects.id', (int) $request->input('project')),
            ))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [SequenceStatus::Live->value])
            ->orderBy('name')
            ->cursorPaginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return SequenceSummaryResource::collection($sequences);
    }

    /**
     * Get a sequence.
     *
     * Returns one sequence with its steps, the message of each step, its filters
     * and its projects.
     */
    public function show(Request $request, int $sequenceId): JsonResponse
    {
        $sequence = Sequence::query()
            ->with(['steps.message.emailIdentity', 'steps.message.template', 'segment', 'projects:projects.id'])
            ->withContactCounts()
            ->findOrFail($sequenceId);

        return SequenceResource::make($sequence)->response();
    }
}
