<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAutomationStatusRequest;
use App\Models\Automation;
use App\Services\AutomationStatusService;
use Illuminate\Http\RedirectResponse;

class AutomationStatusController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(UpdateAutomationStatusRequest $request, AutomationStatusService $automationStatusService, $automationId): RedirectResponse
    {
        $automation = Automation::query()->findOrFail($automationId);

        $automationStatusService->setLive($automation, $request->boolean('is_live'));

        return back();
    }
}
