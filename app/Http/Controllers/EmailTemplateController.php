<?php

namespace App\Http\Controllers;

use App\Actions\CreateEmailTemplate;
use App\Actions\UpdateEmailTemplate;
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

    public function store(StoreEmailTemplateRequest $request, CreateEmailTemplate $createEmailTemplate): RedirectResponse
    {
        $template = $createEmailTemplate->handle(
            $request->user(),
            $request->validated('name'),
            $request->validated('html_content'),
        );

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

    public function update(UpdateEmailTemplateRequest $request, int $emailTemplate, UpdateEmailTemplate $updateEmailTemplate): RedirectResponse
    {
        $emailTemplate = EmailTemplate::query()
            ->findOrFail($emailTemplate);

        $updateEmailTemplate->handle($emailTemplate, [
            'name' => $request->validated('name'),
            'html_content' => $request->validated('html_content'),
        ]);

        return to_route('email-templates.index');
    }

    public function startFromStarter(Request $request, int $starter, CreateEmailTemplate $createEmailTemplate): RedirectResponse
    {
        $source = EmailTemplate::query()
            ->withoutGlobalScope(ClientScope::class)
            ->whereNull('client_id')
            ->active()
            ->findOrFail($starter);

        $template = $createEmailTemplate->handle($request->user(), $source->name, $source->html_content);

        return to_route('email-templates.edit', $template);
    }

    public function startBlank(Request $request, CreateEmailTemplate $createEmailTemplate): RedirectResponse
    {
        $template = $createEmailTemplate->handle(
            $request->user(),
            'Untitled template '.now()->format('Y-m-d H:i'),
            DefaultTemplateContent::html(),
        );

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
