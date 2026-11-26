<?php

namespace App\Http\Controllers\Settings;

use App\Actions\CheckCustomLinkDomain;
use App\Http\Controllers\Controller;
use App\Models\EmailIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomLinkDomainCheckController extends Controller
{
    public function __invoke(Request $request, EmailIdentity $emailIdentity, CheckCustomLinkDomain $checkCustomLinkDomain): JsonResponse
    {
        if (! $emailIdentity->custom_link_domain) {
            return response()->json(['error' => 'No custom link domain configured.'], 404);
        }

        $result = $checkCustomLinkDomain->handle($emailIdentity);

        return response()->json($result, isset($result['error']) ? 422 : 200);
    }
}
