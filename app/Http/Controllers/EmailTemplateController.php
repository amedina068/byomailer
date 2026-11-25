<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmailTemplateRequest;
use App\Http\Requests\UpdateEmailTemplateRequest;
use App\Http\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;
use App\Scopes\ClientScope;
use App\Support\DefaultTemplateContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $showArchived = $request->boolean('archived');

        $userTemplates = EmailTemplate::query()
            ->when($showArchived, fn ($query) => $query->archived(), fn ($query) => $query->active())
            ->latest('id')
            ->simplePaginate(20);

        return Inertia::render('templates/Index', [
            'templates' => Inertia::scroll(
                EmailTemplateResource::collection($userTemplates)
            ),
            'showingArchived' => $showArchived,
            'archivedCount' => EmailTemplate::query()->archived()->count(),
        ]);
    }

    public function create(Request $request): Response
    {
        $systemTemplates = EmailTemplate::query()
            ->system()
            ->active()
            ->latest('id')
            ->get();

        return Inertia::render('templates/Starters', [
            'systemTemplates' => EmailTemplateResource::collection($systemTemplates)->resolve($request),
        ]);
    }

    public function store(StoreEmailTemplateRequest $request): RedirectResponse
    {
        $template = new EmailTemplate;
        $template->forceFill([
            'user_id' => $request->user()->id,
            'client_id' => $request->user()->client_id,
            'name' => $request->validated('name'),
            'html_content' => $request->validated('html_content'),
        ])->save();

        $template->markDefaultIfNone();

        return to_route('email-templates.edit', $template);
    }

    public function edit(int $emailTemplate): Response
    {
        $emailTemplate = EmailTemplate::query()
            ->findOrFail($emailTemplate);

        return Inertia::render('templates/Edit', [
            'template' => EmailTemplateResource::make($emailTemplate)->resolve(),
        ]);
    }

    public function update(UpdateEmailTemplateRequest $request, int $emailTemplate): RedirectResponse
    {
        $emailTemplate = EmailTemplate::query()
            ->findOrFail($emailTemplate);

        $emailTemplate->update([
            'name' => $request->validated('name'),
            'html_content' => $request->validated('html_content'),
        ]);

        return to_route('email-templates.index');
    }

    public function startFromStarter(Request $request, int $starter): RedirectResponse
    {
        $source = EmailTemplate::query()
            ->withoutGlobalScope(ClientScope::class)
            ->whereNull('client_id')
            ->active()
            ->findOrFail($starter);

        $template = new EmailTemplate;
        $template->forceFill([
            'user_id' => $request->user()->id,
            'client_id' => $request->user()->client_id,
            'name' => $source->name,
            'html_content' => $source->html_content,
        ])->save();

        $template->markDefaultIfNone();

        return to_route('email-templates.edit', $template);
    }

    public function startBlank(Request $request): RedirectResponse
    {
        $template = new EmailTemplate;
        $template->forceFill([
            'user_id' => $request->user()->id,
            'client_id' => $request->user()->client_id,
            'name' => 'Untitled template '.now()->format('Y-m-d H:i'),
            'html_content' => DefaultTemplateContent::html(),
        ])->save();

        $template->markDefaultIfNone();

        return to_route('email-templates.edit', $template);
    }

    public function setDefault(int $emailTemplate): RedirectResponse
    {
        $template = EmailTemplate::query()
            ->findOrFail($emailTemplate);

        $template->markDefault();

        return back()->with('success', 'Default template updated.');
    }
}
