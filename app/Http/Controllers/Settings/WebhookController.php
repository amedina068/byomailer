<?php

namespace App\Http\Controllers\Settings;

use App\Actions\CreateWebhookEndpoint;
use App\Actions\DeleteWebhookEndpoint;
use App\Enums\WebhookDisabledReason;
use App\Enums\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreWebhookEndpointRequest;
use App\Http\Resources\Api\WebhookDeliveryResource;
use App\Http\Resources\Api\WebhookEndpointResource;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebhookController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        return Inertia::render('settings/webhooks/Index', [
            'endpoints' => WebhookEndpoint::query()
                ->latest('id')
                ->get()
                ->map(fn (WebhookEndpoint $endpoint) => WebhookEndpointResource::make($endpoint)->resolve($request))
                ->values(),
            'webhookEvents' => collect(WebhookEvent::cases())
                ->map(fn (WebhookEvent $event): array => [
                    'value' => $event->value,
                    'group' => $event->group(),
                    'description' => $event->description(),
                ])
                ->values(),
            'disabledReasons' => array_column(WebhookDisabledReason::cases(), 'value', 'name'),
            'newSecret' => $request->session()->get('newSecret'),
        ]);
    }

    public function store(StoreWebhookEndpointRequest $request, CreateWebhookEndpoint $createWebhookEndpoint): RedirectResponse
    {
        $endpoint = $createWebhookEndpoint->handle(
            $request->user(),
            $request->validated('url'),
            $request->validated('events'),
            $request->validated('description'),
        );

        return to_route('webhooks.index')->with('newSecret', $endpoint->secret);
    }

    public function show(Request $request, int $webhookEndpointId): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        $endpoint = WebhookEndpoint::query()->findOrFail($webhookEndpointId);

        return Inertia::render('settings/webhooks/Show', [
            'endpoint' => WebhookEndpointResource::make($endpoint)->resolve($request),
            'disabledReasons' => array_column(WebhookDisabledReason::cases(), 'value', 'name'),
            'newSecret' => $request->session()->get('newSecret'),
            'deliveries' => Inertia::defer(fn () => $endpoint->deliveries()
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (WebhookDelivery $delivery) => WebhookDeliveryResource::make($delivery)->resolve($request))
                ->values()),
        ]);
    }

    public function destroy(Request $request, int $webhookEndpointId, DeleteWebhookEndpoint $deleteWebhookEndpoint): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $deleteWebhookEndpoint->handle(WebhookEndpoint::query()->findOrFail($webhookEndpointId));

        return to_route('webhooks.index')->with('success', 'Webhook endpoint deleted.');
    }
}
