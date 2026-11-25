<?php

namespace App\Http\Controllers;

use App\Actions\CreateAutomation;
use App\Actions\UpdateAutomation;
use App\Http\Controllers\Concerns\SyncsProjectAssignments;
use App\Http\Requests\StoreAutomationRequest;
use App\Http\Resources\AutomationResource;
use App\Models\Automation;
use App\Models\ContactAttribute;
use App\Models\MailList;
use App\Models\Project;
use App\Models\Sequence;
use App\Models\Tag;
use App\Support\LinkNameCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    use SyncsProjectAssignments;

    public function index(Request $request): Response
    {
        $automations = Automation::query()
            ->with('projects:projects.id')
            ->latest('id')
            ->get();
        $catalogs = $this->catalogOptions($request);

        return Inertia::render('automations/Index', [
            'automations' => AutomationResource::collection($automations)->resolve($request),
            'catalogs' => $catalogs,
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $catalogs = $this->catalogOptions($request);

        return Inertia::render('automations/Create', [
            ...$catalogs,
            'storeRoute' => route('automations.store'),
        ]);
    }

    public function store(StoreAutomationRequest $request, CreateAutomation $createAutomation): RedirectResponse
    {
        $data = $request->automationData();

        $automation = $createAutomation->handle($request->user(), Arr::except($data, ['project_ids']), isLive: true);

        $this->syncProjectsWhenPresent($automation, $data);

        return to_route('automations.index');
    }

    public function edit(Request $request, $automationId): Response
    {
        $automation = Automation::query()
            ->findOrFail($automationId);

        $catalogs = $this->catalogOptions($request);

        return Inertia::render('automations/Edit', [
            'automation' => AutomationResource::make($automation)->resolve($request),
            ...$catalogs,
            'updateRoute' => route('automations.update', $automation),
            'destroyRoute' => route('automations.destroy', $automation),
            'statusRoute' => route('automations.status.update', $automation),
        ]);
    }

    public function update(StoreAutomationRequest $request, Automation $automation, UpdateAutomation $updateAutomation): RedirectResponse
    {
        $data = $request->automationData();

        $updateAutomation->handle($automation, Arr::except($data, ['project_ids']));

        $this->syncProjectsWhenPresent($automation, $data);

        return to_route('automations.index');
    }

    public function destroy(Request $request, $automationId): RedirectResponse
    {
        Automation::query()
            ->findOrFail($automationId)
            ->delete();

        return to_route('automations.index');
    }

    /**
     * @return array{
     *     lists: array<int, array{id: int, name: string}>,
     *     tags: array<int, array{id: int, name: string}>,
     *     sequences: array<int, array{id: int, name: string, status: string}>,
     *     attributes: list<string>,
     *     linkNames: list<string>
     * }
     */
    private function catalogOptions(Request $request): array
    {
        $user = $request->user();

        $lists = MailList::query()->orderBy('name')->get(['id', 'name']);
        $tags = Tag::query()->orderBy('name')->get(['id', 'name']);
        $sequences = Sequence::query()->orderBy('name')->get(['id', 'name', 'status']);
        $attributes = ContactAttribute::query()
            ->where('client_id', $user->client_id)
            ->orderBy('name')
            ->pluck('name');

        return [
            'lists' => $lists->map(fn ($list) => ['id' => $list->id, 'name' => $list->name])->all(),
            'tags' => $tags->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name])->all(),
            'sequences' => $sequences->map(fn ($sequence) => [
                'id' => $sequence->id,
                'name' => $sequence->name,
                'status' => $sequence->status->value,
            ])->all(),
            'attributes' => $attributes->all(),
            'linkNames' => LinkNameCatalog::names(),
        ];
    }
}
