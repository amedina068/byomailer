<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DuplicateEmailTemplateController extends Controller
{
    public function __invoke(Request $request, int $emailTemplate): RedirectResponse
    {
        $source = EmailTemplate::query()->findOrFail($emailTemplate);

        $copy = new EmailTemplate;
        $copy->forceFill([
            'user_id' => $request->user()->id,
            'client_id' => $request->user()->client_id,
            'name' => $source->name.' (copy)',
            'html_content' => $source->html_content,
        ])->save();

        $copy->markDefaultIfNone();

        return to_route('email-templates.index');
    }
}
