<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncProjectsRequest;
use App\Models\Automation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AutomationProjectController extends Controller
{
    public function sync(SyncProjectsRequest $request, Automation $automation): RedirectResponse
    {
        DB::transaction(fn () => $automation->syncProjects($request->projectIds()));

        return back();
    }
}
