<?php

namespace App\Http\Controllers\Api;

use App\Actions\RotateWebhookEndpointSecret;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\WebhookEndpointResource;
use App\Models\WebhookEndpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Webhooks', 'A webhook endpoint receives a signed `POST` request when an event occurs. Only an admin can manage webhook endpoints. Each delivery has the headers `X-Byomailer-Event`, `X-Byomailer-Delivery`, `X-Byomailer-Timestamp` and `X-Byomailer-Signature`. The signature is `sha256=` and the HMAC-SHA256 hex digest of the timestamp, a dot and the raw body, with the endpoint secret as the key.', weight: 12)]
class WebhookEndpointSecretController extends Controller
{
    /**
     * Rotate the secret of a webhook endpoint.
     *
     * Makes a new signing secret and returns it. The old secret stops working at
     * once. Only an admin can call this endpoint.
     */
    public function __invoke(Request $request, int $webhookEndpointId, RotateWebhookEndpointSecret $rotateSecret): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, WebhookEndpoint::ADMIN_ONLY_MESSAGE);

        $endpoint = $rotateSecret->handle(WebhookEndpoint::query()->findOrFail($webhookEndpointId));

        return WebhookEndpointResource::make($endpoint)->withSecret()->response();
    }
}
