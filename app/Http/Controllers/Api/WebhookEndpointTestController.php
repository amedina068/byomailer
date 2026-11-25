<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\WebhookDeliveryResource;
use App\Models\WebhookEndpoint;
use App\Services\WebhookDispatcher;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Webhooks', 'A webhook endpoint receives a signed `POST` request when an event occurs. Only an admin can manage webhook endpoints. Each delivery has the headers `X-Byomailer-Event`, `X-Byomailer-Delivery`, `X-Byomailer-Timestamp` and `X-Byomailer-Signature`. The signature is `sha256=` and the HMAC-SHA256 hex digest of the timestamp, a dot and the raw body, with the endpoint secret as the key.', weight: 12)]
class WebhookEndpointTestController extends Controller
{
    /**
     * Send a test delivery.
     *
     * Queues a `webhook.test` delivery to the endpoint and returns it with the
     * status 202. Only an admin can call this endpoint. The limit is 10 requests a
     * minute for each client.
     */
    public function __invoke(Request $request, int $webhookEndpointId, WebhookDispatcher $dispatcher): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, WebhookEndpoint::ADMIN_ONLY_MESSAGE);

        $endpoint = WebhookEndpoint::query()->findOrFail($webhookEndpointId);

        $delivery = $dispatcher->dispatchTest($endpoint);

        return WebhookDeliveryResource::make($delivery)->response()->setStatusCode(202);
    }
}
