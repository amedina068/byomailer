<?php

namespace App\Http\Controllers;

use App\Actions\UpdateSequence;
use App\Enums\SequenceStatus;
use App\Http\Controllers\Concerns\BuildsFilterFormProps;
use App\Http\Controllers\Concerns\SyncsProjectAssignments;
use App\Http\Requests\SequenceFiltersRequest;
use App\Http\Requests\StoreSequenceRequest;
use App\Http\Requests\UpdateSequenceRequest;
use App\Http\Resources\EmailIdentityResource;
use App\Http\Resources\SegmentResource;
use App\Http\Resources\SequenceResource;
use App\Models\ContactAttribute;
use App\Models\EmailIdentity;
use App\Models\Project;
use App\Models\Sequence;
use App\Services\FilterPreparationService;
use App\Services\SequenceAnalyticsService;
use App\Services\SequenceStatusService;
use App\Services\SesPermissionChecker;
use App\Support\ContentVariables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class SequenceController extends Controller
{
    use BuildsFilterFormProps;
    use SyncsProjectAssignments;

    public function __construct(
        private FilterPreparationService $filterPreparationService,
        private SequenceAnalyticsService $sequenceAnalytics,
        private SequenceStatusService $sequenceStatusService,
    ) {}

    public function index(Request $request): Response
    {
        $sequences = Sequence::query()
            ->with('projects:projects.id')
            ->withCount('steps')
            ->withContactCounts()
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [SequenceStatus::Live->value])
            ->orderBy('name')
            ->get();

        $checker = app(SesPermissionChecker::class);

        return Inertia::render('sequences/Index', [
            'sequences' => SequenceResource::collection($sequences)->resolve($request),
            'sequenceStatuses' => SequenceStatus::options(),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'missingPermissions' => $checker->getMissing($request->user(), SesPermissionChecker::SEND_EMAIL),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('sequences/Create');
    }

    public function store(StoreSequenceRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $sequence = $request->user()
            ->sequences()
            ->create([
                'name' => $data['name'],
                'status' => SequenceStatus::Draft,
            ]);

        $this->syncProjectsWhenPresent($sequence, $data);

        return to_route('sequences.show', $sequence->id);
    }

    public function show(Request $request, $sequenceId): Response
    {
        $sequence = Sequence::query()
            ->with(['steps.message.emailIdentity', 'steps.message.template', 'segment'])
            ->withCount('contacts')
            ->findOrFail($sequenceId);

        $user = $request->user();
        $emailIdentities = EmailIdentity::query()->orderBy('email')->get();
        $sequenceStats = $this->sequenceAnalytics->sequenceStats($sequence);
        $stepStats = $this->sequenceAnalytics->stepStats($sequence->steps);
        $sequenceMailMetrics = $this->sequenceAnalytics->mailMetricsForSequence($sequence);
        $stepMailMetrics = $this->sequenceAnalytics->mailMetricsForSteps($sequence->steps);

        $detectedVariables = $sequence->detectedTemplateVariables();

        $globalDefaults = ContactAttribute::defaultsForNames($detectedVariables);
        $variableDefaults = ContactAttribute::mergePrefillDefaults(
            $detectedVariables,
            $sequence->content_variables ?? [],
            $globalDefaults,
        );

        return Inertia::render('sequences/Show', [
            'sequence' => SequenceResource::make($sequence)->resolve($request),
            'sequenceStatuses' => SequenceStatus::options(),
            'segments' => SegmentResource::collection(
                $user->client->segments()
                    ->activeOrSelected($sequence->segment_id)
                    ->orderBy('name')
                    ->get()
            )->resolve($request),
            ...$this->filterFormProps($request, $this->filterPreparationService),
            'emailIdentities' => EmailIdentityResource::collection($emailIdentities)->resolve($request),
            'sequenceStats' => $sequenceStats,
            'stepStats' => $stepStats,
            'sequenceMailMetrics' => $sequenceMailMetrics,
            'stepMailMetrics' => $stepMailMetrics,
            'detectedVariables' => $detectedVariables,
            'variableDefaults' => $variableDefaults,
            'globalVariableDefaults' => $globalDefaults,
            'statusImpact' => Arr::only(
                $this->sequenceStatusService->sequenceImpact($sequence),
                ['enrolled_contacts', 'pending_mails_due_now'],
            ),
        ]);
    }

    public function updateFilters(SequenceFiltersRequest $request, int $sequenceId, UpdateSequence $updateSequence): RedirectResponse
    {
        $sequence = Sequence::query()->findOrFail($sequenceId);

        $updateSequence->handle($request->user(), $sequence, [
            'filters' => $request->validated('filters', []),
            'segment_id' => $request->validated('segment_id'),
        ]);

        return back()->with('success', 'Filters saved.');
    }

    public function update(UpdateSequenceRequest $request, int $sequenceId, UpdateSequence $updateSequence): RedirectResponse
    {
        $sequence = Sequence::query()->findOrFail($sequenceId);
        $data = $request->validated();

        $updateSequence->handle($request->user(), $sequence, ['name' => $data['name']]);

        $this->syncProjectsWhenPresent($sequence, $data);

        return back()->with('success', 'Sequence updated.');
    }

    public function updateVariables(Request $request, int $sequenceId, UpdateSequence $updateSequence): RedirectResponse
    {
        $sequence = Sequence::query()->findOrFail($sequenceId);
        $validated = $request->validate(ContentVariables::rules());

        $updateSequence->handle($request->user(), $sequence, [
            'content_variables' => $validated['content_variables'] ?? [],
        ]);

        return back()->with('success', 'Variable defaults saved.');
    }
}
