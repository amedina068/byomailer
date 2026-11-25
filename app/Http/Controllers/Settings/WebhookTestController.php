<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\WebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebhookTestController extends Controller
{
    public function __invoke(Request $request, int $webhookEndpointId, WebhookDispatcher $dispatcher): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $endpoint = WebhookEndpoint::query()->findOrFail($webhookEndpointId);

        $dispatcher->dispatchTest($endpoint);

        return back()->with('success', 'Test delivery queued — it will appear in the delivery log shortly.');
    }
}
