<?php

namespace App\Http\Controllers\Settings;

use App\Actions\UpdateWebhookEndpoint;
use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebhookStatusController extends Controller
{
    public function __invoke(Request $request, int $webhookEndpointId, UpdateWebhookEndpoint $updateWebhookEndpoint): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $request->validate(['active' => ['required', 'boolean']]);
        $active = $request->boolean('active');

        $updateWebhookEndpoint->handle(WebhookEndpoint::query()->findOrFail($webhookEndpointId), ['active' => $active]);

        return back()->with('success', $active ? 'Webhook endpoint enabled.' : 'Webhook endpoint disabled.');
    }
}
