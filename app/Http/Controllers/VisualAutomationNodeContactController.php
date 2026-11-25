<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesContactFilter;
use App\Models\Contact;
use App\Models\VisualAutomation;
use App\Models\VisualAutomationContactStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VisualAutomationNodeContactController extends Controller
{
    use ResolvesContactFilter;

    public function index(Request $request, int $visualAutomationId, string $nodeId): Response
    {
        $automation = VisualAutomation::query()->findOrFail($visualAutomationId);

        $node = $automation->findNode($nodeId);

        if (! $node) {
            abort(404);
        }

        $filter = $this->resolveFilter($request, ['completed', 'active']);

        $contacts = $this->paginateContacts(
            $this->contactsQuery($automation, $nodeId, $filter),
            $request,
        );

        return Inertia::render('visual-automations/NodeContacts', [
            'automation' => [
                'id' => $automation->id,
                'name' => $automation->name,
            ],
            'nodeId' => $nodeId,
            'nodeType' => $node['type'] ?? 'unknown',
            'nodeLabel' => $this->nodeLabel($node),
            'contacts' => $contacts,
            'filter' => $filter,
            'stats' => $this->stats($automation, $nodeId),
        ]);
    }

    /**
     * @return array{contacts: int, completed: int, active: int}
     */
    private function stats(VisualAutomation $automation, string $nodeId): array
    {
        $base = VisualAutomationContactStep::query()
            ->where('node_id', $nodeId)
            ->whereHas('enrollment', fn ($q) => $q->where('visual_automation_id', $automation->id));

        return [
            'contacts' => (clone $base)->count(),
            'completed' => (clone $base)->where('status', VisualAutomationContactStep::STATUS_COMPLETED)->count(),
            'active' => (clone $base)->whereIn('status', [
                VisualAutomationContactStep::STATUS_PENDING,
                VisualAutomationContactStep::STATUS_WAITING,
            ])->count(),
        ];
    }

    /**
     * @return Builder<Contact>
     */
    private function contactsQuery(VisualAutomation $automation, string $nodeId, string $filter): Builder
    {
        $stepsTable = (new VisualAutomationContactStep)->getTable();

        return Contact::query()
            ->orderBy('name')
            ->orderBy('id')
            ->whereHas('visualAutomationSteps', function ($q) use ($automation, $nodeId, $filter, $stepsTable): void {
                $q->where("{$stepsTable}.node_id", $nodeId)
                    ->whereHas('enrollment', fn ($eq) => $eq->where('visual_automation_id', $automation->id));

                if ($filter === 'completed') {
                    $q->where("{$stepsTable}.status", VisualAutomationContactStep::STATUS_COMPLETED);
                } elseif ($filter === 'active') {
                    $q->whereIn("{$stepsTable}.status", [
                        VisualAutomationContactStep::STATUS_PENDING,
                        VisualAutomationContactStep::STATUS_WAITING,
                    ]);
                }
            });
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodeLabel(array $node): string
    {
        $type = $node['type'] ?? 'unknown';

        return match ($type) {
            'trigger' => 'Trigger',
            'action' => 'Action',
            'delay' => 'Delay',
            'condition' => 'Condition',
            default => ucfirst($type),
        };
    }
}
