<?php

namespace App\Mcp\Tools;

use App\Enums\SequenceStatus;
use App\Models\Sequence;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the sequences belonging to the authenticated account, returning each id, name, status, step count and contact count. Use the returned id with the sequence-stats-tool or sequence-content-tool tools.')]
class ListSequencesTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(SequenceStatus::inputValues())],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $sequences = Sequence::query()
            ->withCount(['steps', 'contacts'])
            ->when(isset($validated['status']), fn ($query) => $query->where('status', SequenceStatus::fromInput($validated['status'])))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [SequenceStatus::Live->value])
            ->orderBy('name')
            ->limit((int) ($validated['limit'] ?? 25))
            ->get()
            ->map(fn (Sequence $sequence): array => [
                'id' => $sequence->id,
                'name' => $sequence->name,
                'status' => $sequence->status->value,
                'steps_count' => (int) $sequence->steps_count,
                'contacts_count' => (int) $sequence->contacts_count,
            ])
            ->all();

        return Response::structured([
            'sequences' => $sequences,
            'count' => count($sequences),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(SequenceStatus::inputValues())
                ->description('Optional status filter. paused is the same as draft: a paused sequence has the status draft.'),
            'search' => $schema->string()
                ->description('Optional case-insensitive search against the sequence name.'),
            'limit' => $schema->integer()
                ->description('Maximum number of sequences to return (1-100, default 25).'),
        ];
    }
}
