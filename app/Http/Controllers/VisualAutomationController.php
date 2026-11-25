<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SyncsProjectAssignments;
use App\Http\Requests\StoreVisualAutomationRequest;
use App\Http\Resources\TopicResource;
use App\Http\Resources\VisualAutomationResource;
use App\Models\MailList;
use App\Models\Project;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\VisualAutomation;
use App\Models\VisualAutomationContactStep;
use App\Support\LinkNameCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class VisualAutomationController extends Controller
{
    use SyncsProjectAssignments;

    public function index(Request $request): Response
    {
        $automations = VisualAutomation::query()
            ->withCount('enrollments')
            ->with('projects:projects.id')
            ->latest('id')
            ->get();

        return Inertia::render('visual-automations/Index', [
            'automations' => VisualAutomationResource::collection($automations)->resolve($request),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $catalogs = $this->catalogOptions($request);

        return Inertia::render('visual-automations/Show', [
            'mode' => 'create',
            'automation' => null,
            ...$catalogs,
        ]);
    }

    public function store(StoreVisualAutomationRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $automation = $request->user()->visualAutomations()->create(Arr::except($data, ['project_ids']));

        $this->syncProjectsWhenPresent($automation, $data);

        return to_route('visual-automations.show', $automation);
    }

    public function show(Request $request, $visualAutomationId): Response
    {
        $automation = VisualAutomation::query()
            ->withCount('enrollments')
            ->findOrFail($visualAutomationId);

        $nodeStepCounts = VisualAutomationContactStep::query()
            ->whereHas('enrollment', fn ($q) => $q->where('visual_automation_id', $automation->id))
            ->whereIn('status', [
                VisualAutomationContactStep::STATUS_PENDING,
                VisualAutomationContactStep::STATUS_WAITING,
            ])
            ->selectRaw('node_id, count(*) as count')
            ->groupBy('node_id')
            ->pluck('count', 'node_id')
            ->all();

        $catalogs = $this->catalogOptions($request);

        return Inertia::render('visual-automations/Show', [
            'mode' => 'edit',
            'automation' => VisualAutomationResource::make($automation)->resolve($request),
            'nodeStepCounts' => $nodeStepCounts,
            ...$catalogs,
        ]);
    }

    public function update(StoreVisualAutomationRequest $request, $visualAutomationId): RedirectResponse
    {
        $automation = VisualAutomation::query()
            ->findOrFail($visualAutomationId);

        $data = $request->validated();

        $automation->update(Arr::except($data, ['project_ids']));

        $this->syncProjectsWhenPresent($automation, $data);

        return back();
    }

    public function destroy(Request $request, $visualAutomationId): RedirectResponse
    {
        VisualAutomation::query()
            ->findOrFail($visualAutomationId)
            ->delete();

        return to_route('visual-automations.index');
    }

    /**
     * @return array{
     *     lists: array<int, array{id: int, name: string}>,
     *     tags: array<int, array{id: int, name: string}>,
     *     sequences: array<int, array{id: int, name: string, status: string}>,
     *     linkNames: list<string>
     * }
     */
    private function catalogOptions(Request $request): array
    {
        $lists = MailList::query()->orderBy('name')->get(['id', 'name']);
        $tags = Tag::query()->orderBy('name')->get(['id', 'name']);
        $topics = Topic::query()
            ->with(['list:id,name'])
            ->orderBy('name')
            ->get();
        $sequences = Sequence::query()->orderBy('name')->get(['id', 'name', 'status']);

        return [
            'lists' => $lists->map(fn ($list) => ['id' => $list->id, 'name' => $list->name])->all(),
            'tags' => $tags->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name])->all(),
            'topics' => TopicResource::collection($topics)->resolve($request),
            'sequences' => $sequences->map(fn ($sequence) => [
                'id' => $sequence->id,
                'name' => $sequence->name,
                'status' => $sequence->status->value,
            ])->all(),
            'linkNames' => LinkNameCatalog::names(),
        ];
    }
}
