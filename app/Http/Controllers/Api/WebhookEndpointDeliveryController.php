<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\WebhookDeliveryResource;
use App\Models\WebhookEndpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Webhooks', 'A webhook endpoint receives a signed `POST` request when an event occurs. Only an admin can manage webhook endpoints. Each delivery has the headers `X-Byomailer-Event`, `X-Byomailer-Delivery`, `X-Byomailer-Timestamp` and `X-Byomailer-Signature`. The signature is `sha256=` and the HMAC-SHA256 hex digest of the timestamp, a dot and the raw body, with the endpoint secret as the key.', weight: 12)]
class WebhookEndpointDeliveryController extends Controller
{
    /**
     * List the deliveries of a webhook endpoint.
     *
     * Returns the deliveries of one endpoint, newest first, with the response
     * status and the attempts of each one. Only an admin can call this endpoint.
     */
    #[QueryParameter('per_page', description: 'The number of items on one page, from 1 to 100.', type: 'int', default: 25)]
    #[QueryParameter('cursor', description: 'The cursor from `meta.next_cursor` or `meta.prev_cursor` of the previous response.', type: 'string')]
    public function __invoke(Request $request, int $webhookEndpointId): AnonymousResourceCollection
    {
        abort_unless($request->user()->isAdmin(), 403, WebhookEndpoint::ADMIN_ONLY_MESSAGE);

        $endpoint = WebhookEndpoint::query()->findOrFail($webhookEndpointId);

        $deliveries = $endpoint->deliveries()
            ->latest('id')
            ->cursorPaginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return WebhookDeliveryResource::collection($deliveries);
    }
}
