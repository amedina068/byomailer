<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncProjectsRequest;
use App\Models\Sequence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class SequenceProjectController extends Controller
{
    public function sync(SyncProjectsRequest $request, Sequence $sequence): RedirectResponse
    {
        DB::transaction(fn () => $sequence->syncProjects($request->projectIds()));

        return back();
    }
}
