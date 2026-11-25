<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmailLogResource;
use App\Models\EmailLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailLogController extends Controller
{
    public function show(int $emailLogId): Response
    {
        $emailLog = EmailLog::query()->findOrFail($emailLogId);

        return Inertia::render('email-logs/Show', [
            'emailLog' => EmailLogResource::make($emailLog)->resolve(),
        ]);
    }

    public function index(Request $request): Response
    {
        $query = EmailLog::query()
            ->latest('logged_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('operation')) {
            $query->where('operation', $request->input('operation'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('recipient', 'like', '%'.$search.'%')
                    ->orWhere('message_id', 'like', '%'.$search.'%');
            });
        }

        $logs = $query->paginate(25)->withQueryString();

        return Inertia::render('email-logs/Index', [
            'emailLogs' => EmailLogResource::collection($logs)->resolve($request),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'next_page_url' => $logs->nextPageUrl(),
                'prev_page_url' => $logs->previousPageUrl(),
            ],
            'filters' => $request->only(['status', 'operation', 'search']),
        ]);
    }
}
