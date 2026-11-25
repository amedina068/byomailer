<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use Illuminate\Http\RedirectResponse;

class EmailTemplateArchiveController extends Controller
{
    public function store(int $emailTemplate): RedirectResponse
    {
        $template = EmailTemplate::query()->findOrFail($emailTemplate);

        if ($template->is_default) {
            return back()->withErrors([
                'template' => 'You cannot archive the default template. Set another template as default first.',
            ]);
        }

        $template->archive();

        return to_route('email-templates.index')->with('success', 'Template archived.');
    }

    public function destroy(int $emailTemplate): RedirectResponse
    {
        $template = EmailTemplate::query()->findOrFail($emailTemplate);
        $template->unarchive();

        return to_route('email-templates.index')->with('success', 'Template restored.');
    }
}
