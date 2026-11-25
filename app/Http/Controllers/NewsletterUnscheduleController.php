<?php

namespace App\Http\Controllers;

use App\Enums\NewsletterStatus;
use App\Models\Newsletter;
use App\Services\NewsletterScheduleService;
use Illuminate\Http\RedirectResponse;

class NewsletterUnscheduleController extends Controller
{
    public function __invoke(Newsletter $newsletter, NewsletterScheduleService $newsletterScheduleService): RedirectResponse
    {
        if ($newsletter->status !== NewsletterStatus::Scheduled) {
            return back()->with('error', 'Only a scheduled newsletter can be unscheduled.');
        }

        if (! $newsletterScheduleService->unschedule($newsletter)) {
            return back()->with('error', NewsletterScheduleService::UNSCHEDULE_FAILED);
        }

        return back()->with('success', 'The newsletter is a draft again. It will not send.');
    }
}
