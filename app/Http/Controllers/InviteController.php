<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteInvitationRequest;
use App\Models\User;
use App\Support\PreviousUrlSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InviteController extends Controller
{
    private const EXPIRED_MESSAGE = 'This invitation has expired, please contact your admin to get a new one.';

    public function show(Request $request, User $user): Response|HttpResponse
    {
        $user->loadMissing('client');

        if (! $this->invitationIsValid($request, $user)) {
            return $this->expiredResponse($request);
        }

        return Inertia::render('invites/Accept', [
            'invite' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'client_name' => $user->client->name,
                'expires_at' => $user->invite_expires_at,
            ],
            'signature' => [
                'signature' => $request->query('signature'),
                'expires' => $request->query('expires'),
            ],
        ]);
    }

    public function store(CompleteInvitationRequest $request, User $user): Response|RedirectResponse|HttpResponse
    {
        if (! $this->invitationIsValid($request, $user)) {
            return $this->expiredResponse($request);
        }

        $providedEmail = Str::lower($request->string('email')->toString());

        if ($providedEmail !== Str::lower($user->email)) {
            throw ValidationException::withMessages([
                'email' => 'The email address does not match this invitation.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($request->string('password')->toString()),
            'invite_expires_at' => null,
            'email_verified_at' => now(),
        ])->save();

        $user->refresh();

        Auth::login($user);

        return redirect()->route('dashboard')->with('success', 'Welcome aboard!');
    }

    private function invitationIsValid(Request $request, User $user): bool
    {
        return ($request->hasValidSignature() || PreviousUrlSignature::isValid($request))
            && ! $user->isDeactivated()
            && $user->invite_expires_at !== null
            && $user->invite_expires_at->isFuture();
    }

    private function expiredResponse(Request $request): HttpResponse
    {
        return Inertia::render('invites/Expired', [
            'message' => self::EXPIRED_MESSAGE,
        ])->toResponse($request)->setStatusCode(410);
    }
}
