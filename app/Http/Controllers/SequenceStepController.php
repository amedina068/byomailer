<?php

namespace App\Http\Controllers;

use App\Actions\CreateSequenceStep;
use App\Actions\DeleteSequenceStep;
use App\Actions\UpdateSequenceStep;
use App\Enums\SequenceStatus;
use App\Http\Controllers\Concerns\BuildsFilterFormProps;
use App\Http\Requests\StoreSequenceStepRequest;
use App\Http\Requests\UpdateSequenceStepRequest;
use App\Http\Resources\EmailIdentityResource;
use App\Http\Resources\EmailTemplateResource;
use App\Http\Resources\SequenceStepResource;
use App\Models\EmailIdentity;
use App\Models\EmailTemplate;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Services\FilterPreparationService;
use App\Services\SequenceAnalyticsService;
use App\Services\SequenceStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SequenceStepController extends Controller
{
    use BuildsFilterFormProps;

    public function show(
        Request $request,
        int $sequenceId,
        int $sequenceStepId,
        SequenceAnalyticsService $sequenceAnalytics,
        FilterPreparationService $filterPreparationService,
        SequenceStatusService $sequenceStatusService,
    ): Response {
        $sequence = Sequence::query()
            ->with(['steps.message.emailIdentity', 'steps.message.template'])
            ->withCount('contacts')
            ->findOrFail($sequenceId);

        $step = $sequence->steps->firstWhere('id', $sequenceStepId);

        if (! $step instanceof SequenceStep) {
            abort(404);
        }

        $stepIndex = $sequence->steps->values()->search(fn (SequenceStep $s): bool => $s->id === $step->id);

        $emailIdentities = EmailIdentity::query()->orderBy('email')->get();
        $stepStats = $sequenceAnalytics->statsForStep($step);
        $stepMailMetrics = $sequenceAnalytics->mailMetricsForStep($step);

        $defaultTemplate = EmailTemplate::query()
            ->where('is_default', true)
            ->first();

        return Inertia::render('sequences/steps/Show', [
            'sequence' => [
                'id' => $sequence->id,
                'name' => $sequence->name,
                'status' => $sequence->status->value,
            ],
            'step' => SequenceStepResource::make($step)->resolve($request),
            'sequenceStatuses' => SequenceStatus::options(),
            'stepIndex' => $stepIndex,
            'emailIdentities' => EmailIdentityResource::collection($emailIdentities)->resolve($request),
            'stepStats' => $stepStats,
            'stepMailMetrics' => $stepMailMetrics,
            'defaultTemplate' => $defaultTemplate
                ? EmailTemplateResource::make($defaultTemplate)->resolve($request)
                : null,
            'statusImpact' => $sequenceStatusService->stepImpact($sequence, $step),
            ...$this->filterFormProps($request, $filterPreparationService),
        ]);
    }

    public function store(StoreSequenceStepRequest $request, int $sequenceId, CreateSequenceStep $createSequenceStep): RedirectResponse
    {
        $sequence = Sequence::query()->findOrFail($sequenceId);
        $identity = EmailIdentity::query()->findOrFail($request->validated('email_identity_id'));

        $step = $createSequenceStep->handle($request->user(), $sequence, $identity, [
            'delay_days' => $request->validated('delay_days'),
            'delay_type' => $request->validated('delay_type'),
        ]);

        return to_route('sequences.steps.show', [
            'sequence' => $sequence->id,
            'sequenceStep' => $step->id,
        ])->with('success', 'Sequence step created');
    }

    public function update(UpdateSequenceStepRequest $request, int $sequenceId, int $sequenceStepId, UpdateSequenceStep $updateSequenceStep): RedirectResponse
    {
        $sequenceStep = $this->findStep($sequenceId, $sequenceStepId);
        $data = $request->validated();

        $updateSequenceStep->handle($request->user(), $sequenceStep, [
            'delay_days' => $data['delay_days'],
            'delay_type' => $data['delay_type'],
            'subject' => $data['subject'] ?? null,
            'preview_text' => $data['preview_text'] ?? null,
            'email_identity_id' => $data['email_identity_id'],
            'html_content' => $data['content'] ?? '',
            'email_template_id' => $data['email_template_id'] ?? null,
        ]);

        return to_route('sequences.steps.show', [
            'sequence' => $sequenceId,
            'sequenceStep' => $sequenceStep->id,
        ])->with('success', 'Step saved.');
    }

    public function destroy(int $sequenceId, int $sequenceStepId, DeleteSequenceStep $deleteSequenceStep): RedirectResponse
    {
        $deleteSequenceStep->handle($this->findStep($sequenceId, $sequenceStepId));

        return to_route('sequences.show', $sequenceId)->with('success', 'Sequence step deleted');
    }

    private function findStep(int $sequenceId, int $sequenceStepId): SequenceStep
    {
        return SequenceStep::query()
            ->ofCurrentClient()
            ->where('sequence_id', $sequenceId)
            ->findOrFail($sequenceStepId);
    }
}
