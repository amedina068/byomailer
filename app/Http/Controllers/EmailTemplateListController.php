<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailTemplateListController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $userTemplates = EmailTemplate::query()
            ->active()
            ->latest('id')
            ->simplePaginate(20);

        return response()->json([
            'templates' => [
                'data' => EmailTemplateResource::collection($userTemplates->items())->resolve($request),
                'next_page_url' => $userTemplates->nextPageUrl(),
            ],
        ]);
    }
}
