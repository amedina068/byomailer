<?php

namespace App\Http\Controllers;

use App\Actions\CreateEmailTemplate;
use App\Models\EmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DuplicateEmailTemplateController extends Controller
{
    public function __invoke(Request $request, int $emailTemplate, CreateEmailTemplate $createEmailTemplate): RedirectResponse
    {
        $source = EmailTemplate::query()->findOrFail($emailTemplate);

        $createEmailTemplate->handle($request->user(), $source->name.' (copy)', $source->html_content);

        return to_route('email-templates.index');
    }
}
