<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateVisualAutomationStatusRequest;
use App\Models\VisualAutomation;
use Illuminate\Http\RedirectResponse;

class VisualAutomationStatusController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(UpdateVisualAutomationStatusRequest $request, $visualAutomationId): RedirectResponse
    {
        $automation = VisualAutomation::query()
            ->findOrFail($visualAutomationId);

        $automation->update($request->validated());

        return back();
    }
}
