<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateWebhookEndpoint;
use App\Actions\DeleteWebhookEndpoint;
use App\Actions\UpdateWebhookEndpoint;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreWebhookEndpointRequest;
use App\Http\Requests\Api\UpdateWebhookEndpointRequest;
use App\Http\Resources\Api\WebhookEndpointResource;
use App\Models\WebhookEndpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Webhooks', 'A webhook endpoint receives a signed `POST` request when an event occurs. Only an admin can manage webhook endpoints. Each delivery has the headers `X-Byomailer-Event`, `X-Byomailer-Delivery`, `X-Byomailer-Timestamp` and `X-Byomailer-Signature`. The signature is `sha256=` and the HMAC-SHA256 hex digest of the timestamp, a dot and the raw body, with the endpoint secret as the key.', weight: 12)]
class WebhookEndpointController extends Controller
{
    /**
     * List webhook endpoints.
     *
     * Returns the webhook endpoints of your client, newest first. Only an admin
     * can call this endpoint.
     */
    #[QueryParameter('per_page', description: 'The number of items on one page, from 1 to 100.', type: 'int', default: 25)]
    #[QueryParameter('cursor', description: 'The cursor from `meta.next_cursor` or `meta.prev_cursor` of the previous response.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->isAdmin(), 403, WebhookEndpoint::ADMIN_ONLY_MESSAGE);

        $endpoints = WebhookEndpoint::query()
            ->latest('id')
            ->cursorPaginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return WebhookEndpointResource::collection($endpoints);
    }

    /**
     * Create a webhook endpoint.
     *
     * Creates a webhook endpoint for the given events. The URL must be a public
     * HTTPS URL. The response has the signing `secret`. Keep it: the API shows it
     * only in this response and after a secret rotation.
     */
    public function store(StoreWebhookEndpointRequest $request, CreateWebhookEndpoint $createWebhookEndpoint): JsonResponse
    {
        $endpoint = $createWebhookEndpoint->handle(
            $request->user(),
            $request->validated('url'),
            $request->validated('events'),
            $request->validated('description'),
        );

        return WebhookEndpointResource::make($endpoint)->withSecret()
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Get a webhook endpoint.
     *
     * Returns one webhook endpoint, without its secret. Only an admin can call
     * this endpoint.
     */
    public function show(Request $request, int $webhookEndpointId): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, WebhookEndpoint::ADMIN_ONLY_MESSAGE);

        $endpoint = WebhookEndpoint::query()->findOrFail($webhookEndpointId);

        return WebhookEndpointResource::make($endpoint)->response();
    }

    /**
     * Update a webhook endpoint.
     *
     * Changes the description, or turns the endpoint on or off with `active`.
     */
    public function update(UpdateWebhookEndpointRequest $request, int $webhookEndpointId, UpdateWebhookEndpoint $updateWebhookEndpoint): JsonResponse
    {
        $endpoint = $updateWebhookEndpoint->handle(
            WebhookEndpoint::query()->findOrFail($webhookEndpointId),
            $request->attributesToUpdate(),
        );

        return WebhookEndpointResource::make($endpoint)->response();
    }

    /**
     * Delete a webhook endpoint.
     *
     * Deletes the webhook endpoint. Only an admin can call this endpoint.
     */
    public function destroy(Request $request, int $webhookEndpointId, DeleteWebhookEndpoint $deleteWebhookEndpoint): Response
    {
        abort_unless($request->user()->isAdmin(), 403, WebhookEndpoint::ADMIN_ONLY_MESSAGE);

        $deleteWebhookEndpoint->handle(WebhookEndpoint::query()->findOrFail($webhookEndpointId));

        return response()->noContent();
    }
}
