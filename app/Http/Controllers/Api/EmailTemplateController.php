<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EmailTemplateResource;
use App\Models\EmailTemplate;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Email templates', 'An email template is the HTML layout around the content of a newsletter or a sequence step. The API reads templates. Change them in the dashboard.', weight: 8)]
class EmailTemplateController extends Controller
{
    /**
     * List email templates.
     *
     * Returns the email templates of your client, newest first. Archived templates
     * are left out unless `archived` is true.
     */
    #[QueryParameter('archived', description: 'Return only archived templates.', type: 'bool', default: false)]
    #[QueryParameter('is_default', description: 'Return only the default template (true) or only the other templates (false).', type: 'bool')]
    #[QueryParameter('cursor', description: 'The cursor from `meta.next_cursor` or `meta.prev_cursor` of the previous response.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $templates = EmailTemplate::query()
            ->when($request->boolean('archived'), fn ($q) => $q->whereNotNull('archived_at'))
            ->when(! $request->boolean('archived'), fn ($q) => $q->whereNull('archived_at'))
            ->when($request->has('is_default'), fn ($q) => $q->where('is_default', $request->boolean('is_default')))
            ->latest('id')
            ->cursorPaginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return EmailTemplateResource::collection($templates);
    }

    /**
     * Get an email template.
     *
     * Returns one email template with its HTML.
     */
    public function show(Request $request, int $emailTemplateId): JsonResponse
    {
        $template = EmailTemplate::query()->findOrFail($emailTemplateId);

        return EmailTemplateResource::make($template)->response();
    }

    /**
     * Get the default email template.
     *
     * Returns the default email template of your client. Returns 404 when no
     * template is the default.
     */
    #[Response(404, description: 'No template is the default.', type: 'array{message: string}')]
    public function default(Request $request): JsonResponse
    {
        $template = EmailTemplate::query()
            ->where('is_default', true)
            ->whereNull('archived_at')
            ->firstOrFail();

        return EmailTemplateResource::make($template)->response();
    }
}
