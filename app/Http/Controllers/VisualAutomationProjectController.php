<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncProjectsRequest;
use App\Models\VisualAutomation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class VisualAutomationProjectController extends Controller
{
    public function sync(SyncProjectsRequest $request, VisualAutomation $visualAutomation): RedirectResponse
    {
        DB::transaction(fn () => $visualAutomation->syncProjects($request->projectIds()));

        return back();
    }
}
