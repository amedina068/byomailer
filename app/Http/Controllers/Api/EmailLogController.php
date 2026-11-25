<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmailLogResource;
use App\Models\EmailLog;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Email logs', 'An email log is one record of the email activity of your client, for example one send, one bounce or one complaint.', weight: 10)]
class EmailLogController extends Controller
{
    /**
     * List email logs.
     *
     * Returns the email logs of your client, newest first.
     */
    #[QueryParameter('status', description: 'Return only logs with this status.', type: 'string')]
    #[QueryParameter('operation', description: 'Return only logs of this operation.', type: 'string')]
    #[QueryParameter('recipient', description: 'Return only logs whose recipient contains this text.', type: 'string')]
    #[QueryParameter('per_page', description: 'The number of items on one page, from 1 to 100.', type: 'int', default: 25)]
    #[QueryParameter('cursor', description: 'The cursor from `meta.next_cursor` or `meta.prev_cursor` of the previous response.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = EmailLog::query()
            ->latest('logged_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('operation')) {
            $query->where('operation', $request->input('operation'));
        }

        if ($request->filled('recipient')) {
            $query->where('recipient', 'like', '%'.$request->input('recipient').'%');
        }

        $logs = $query->cursorPaginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return EmailLogResource::collection($logs);
    }

    /**
     * Get an email log.
     *
     * Returns one email log.
     */
    public function show(Request $request, int $emailLogId): JsonResponse
    {
        $log = EmailLog::query()
            ->findOrFail($emailLogId);

        return EmailLogResource::make($log)->response();
    }
}
