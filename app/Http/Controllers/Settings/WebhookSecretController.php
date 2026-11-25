<?php

namespace App\Http\Controllers\Settings;

use App\Actions\RotateWebhookEndpointSecret;
use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebhookSecretController extends Controller
{
    public function __invoke(Request $request, int $webhookEndpointId, RotateWebhookEndpointSecret $rotateSecret): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $endpoint = $rotateSecret->handle(WebhookEndpoint::query()->findOrFail($webhookEndpointId));

        return to_route('webhooks.show', $endpoint->id)->with('newSecret', $endpoint->secret);
    }
}
