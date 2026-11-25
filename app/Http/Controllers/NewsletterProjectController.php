<?php

namespace App\Http\Controllers;

use App\Actions\UpdateNewsletterLabels;
use App\Http\Requests\SyncProjectsRequest;
use App\Models\Newsletter;
use Illuminate\Http\RedirectResponse;

class NewsletterProjectController extends Controller
{
    public function sync(SyncProjectsRequest $request, Newsletter $newsletter, UpdateNewsletterLabels $updateNewsletterLabels): RedirectResponse
    {
        $updateNewsletterLabels->handle($newsletter, ['project_ids' => $request->projectIds()]);

        return back();
    }
}
