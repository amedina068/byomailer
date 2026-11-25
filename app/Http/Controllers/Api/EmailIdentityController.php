<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EmailIdentityResource;
use App\Models\EmailIdentity;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Email identities', 'An email identity is a sender domain or a sender address in the Amazon SES account of your client. The API reads identities. Add them in the dashboard.', weight: 9)]
class EmailIdentityController extends Controller
{
    /**
     * List email identities.
     *
     * Returns the email identities of your client, newest first.
     */
    #[QueryParameter('identity_status', description: 'Return only identities with this verification status, for example `verified`.', type: 'string')]
    #[QueryParameter('per_page', description: 'The number of items on one page, from 1 to 100.', type: 'int', default: 25)]
    #[QueryParameter('cursor', description: 'The cursor from `meta.next_cursor` or `meta.prev_cursor` of the previous response.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $identities = EmailIdentity::query()
            ->when($request->filled('identity_status'), fn ($query) => $query->where('identity_status', $request->input('identity_status')))
            ->latest('id')
            ->cursorPaginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return EmailIdentityResource::collection($identities);
    }

    /**
     * Get an email identity.
     *
     * Returns one email identity with its verification status.
     */
    public function show(Request $request, int $emailIdentityId): JsonResponse
    {
        $identity = EmailIdentity::query()->findOrFail($emailIdentityId);

        return EmailIdentityResource::make($identity)->response();
    }
}
