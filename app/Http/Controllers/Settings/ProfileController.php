<?php

namespace App\Http\Controllers\Settings;

use App\Actions\DeleteUser;
use App\Actions\UpdateAccountSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Services\SunsetPolicy;
use DateTimeZone;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request, SunsetPolicy $sunsetPolicy): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'timezones' => DateTimeZone::listIdentifiers(),
            'sunsetInactiveDays' => $request->user()->client->sunset_inactive_days,
            'postalAddress' => $request->user()->client->postal_address,
            'isInstanceOwner' => $request->user()->isOwner(),
            'sunsetPreview' => Inertia::defer(fn (): ?array => $sunsetPolicy->preview($request->user()->client)),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, UpdateAccountSettings $updateAccountSettings): RedirectResponse
    {
        $validated = $request->validated();

        $request->user()->fill(collect($validated)->except(['timezone', 'sunset_inactive_days', 'postal_address'])->all());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        $updateAccountSettings->handle(
            $request->user()->client,
            collect($validated)->only(['timezone', 'sunset_inactive_days', 'postal_address'])->all(),
        );

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, DeleteUser $deleteUser): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $deleteUser->handle($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
