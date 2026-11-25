<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateReplyToRequest;
use App\Models\EmailIdentity;
use Illuminate\Http\JsonResponse;

class EmailIdentityReplyToController extends Controller
{
    public function __invoke(UpdateReplyToRequest $request, EmailIdentity $emailIdentity): JsonResponse
    {
        $emailIdentity->update($request->replyTo());

        return response()->json(['success' => true]);
    }
}
