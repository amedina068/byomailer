<?php

namespace App\Http\Controllers\Settings;

use App\Actions\UpdateEmailIdentityReplyTo;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateReplyToRequest;
use App\Models\EmailIdentity;
use Illuminate\Http\JsonResponse;

class EmailIdentityReplyToController extends Controller
{
    public function __invoke(UpdateReplyToRequest $request, EmailIdentity $emailIdentity, UpdateEmailIdentityReplyTo $updateReplyTo): JsonResponse
    {
        $updateReplyTo->handle(
            $emailIdentity,
            $request->validated('reply_to_email'),
            $request->validated('reply_to_name'),
        );

        return response()->json(['success' => true]);
    }
}
