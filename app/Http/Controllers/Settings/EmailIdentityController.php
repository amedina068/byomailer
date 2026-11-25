<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\CloudflareException;
use App\Exceptions\EmailIdentityProvisioningException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmailIdentityRequest;
use App\Http\Resources\EmailIdentityResource;
use App\Models\EmailIdentity;
use App\Services\CloudflareClient;
use App\Services\EmailIdentityProvisioningService;
use App\Support\EmailIdentityUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmailIdentityController extends Controller
{
    public function __construct(
        private EmailIdentityProvisioningService $provisioningService,
    ) {}

    public function index(Request $request): Response
    {
        $identities = EmailIdentity::query()
            ->with('dnsRecords')
            ->latest('id')
            ->get();

        return Inertia::render('settings/identities/Index', [
            'identities' => EmailIdentityResource::collection($identities)->resolve($request),
            'hasCredential' => $request->user()->sesCredential !== null,
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user()->sesCredential === null) {
            return redirect()
                ->route('email-credentials.index')
                ->with('error', 'Add your AWS credentials before you add an email identity.');
        }

        $existingDomains = $request->user()
            ->client
            ->emailIdentities()
            ->whereNotNull('domain')
            ->pluck('domain')
            ->unique()
            ->values()
            ->all();

        return Inertia::render('settings/identities/Create', [
            'existingDomains' => $existingDomains,
            'hasCredential' => true,
        ]);
    }

    public function show(Request $request, EmailIdentity $emailIdentity): Response
    {
        $emailIdentity->load('dnsRecords');

        return Inertia::render('settings/identities/Show', [
            'identity' => EmailIdentityResource::make($emailIdentity)->resolve($request),
            'usage' => EmailIdentityUsage::of($emailIdentity),
        ]);
    }

    public function store(StoreEmailIdentityRequest $request): JsonResponse
    {
        try {
            $identity = $this->provisioningService->provision($request->user(), $request->validated());
        } catch (EmailIdentityProvisioningException $e) {
            return $this->storeError($e->getMessage());
        }

        return response()->json([
            'identity' => EmailIdentityResource::make($identity)->resolve($request),
        ]);
    }

    /**
     * Lock the identity row before the usage check. A newsletter schedule
     * locks the same row before it goes live, so the two cannot overlap.
     */
    public function destroy(Request $request, EmailIdentity $emailIdentity, CloudflareClient $cloudflare): RedirectResponse
    {
        DB::transaction(function () use ($emailIdentity): void {
            EmailIdentity::query()->whereKey($emailIdentity->getKey())->lockForUpdate()->first();

            if (EmailIdentityUsage::isInUse(EmailIdentityUsage::of($emailIdentity))) {
                throw ValidationException::withMessages([
                    'identity' => 'Live newsletters, sequences or list opt-in emails send from this email identity. Change their sender or stop them, then remove the email identity.',
                ]);
            }

            $emailIdentity->delete();
        });

        $this->releaseLinkDomain($emailIdentity, $cloudflare);

        return redirect()->route('identities.index')->with('success', 'Email identity removed.');
    }

    /**
     * Free the Cloudflare custom hostname tied to this identity so deleting an
     * identity does not strand a billable hostname in Cloudflare for SaaS.
     */
    private function releaseLinkDomain(EmailIdentity $emailIdentity, CloudflareClient $cloudflare): void
    {
        if ($emailIdentity->custom_link_domain) {
            Cache::forget("link-domain-host:{$emailIdentity->custom_link_domain}");
        }

        if (! $emailIdentity->cf_custom_hostname_id) {
            return;
        }

        try {
            $cloudflare->deleteCustomHostname($emailIdentity->cf_custom_hostname_id);
        } catch (CloudflareException $e) {
            Log::warning('Failed to delete Cloudflare custom hostname on identity removal', [
                'email_identity_id' => $emailIdentity->id,
                'hostname_id' => $emailIdentity->cf_custom_hostname_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function storeError(string $message): JsonResponse
    {
        return response()->json([
            'errors' => ['email' => [$message]],
            'error' => $message,
        ], 422);
    }
}
