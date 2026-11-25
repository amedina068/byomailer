<?php

namespace App\Http\Controllers\Settings;

use App\Actions\DeleteUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\Client;
use App\Services\SunsetPolicy;
use DateTimeZone;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
            'sunsetPreview' => Inertia::defer(fn (): ?array => $this->sunsetPreview($request->user()->client, $sunsetPolicy)),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $request->user()->fill(collect($validated)->except(['timezone', 'sunset_inactive_days', 'postal_address'])->all());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        $client = $request->user()->client;
        $client->update(['timezone' => $validated['timezone']]);

        if (array_key_exists('sunset_inactive_days', $validated)) {
            $days = $validated['sunset_inactive_days'];
            $client->update(['sunset_inactive_days' => $days === null ? null : (int) $days]);
        }

        if (array_key_exists('postal_address', $validated)) {
            $client->update(['postal_address' => $validated['postal_address']]);
        }

        return to_route('profile.edit');
    }

    /**
     * The number of contacts the next sunset run marks. The count scans the
     * client's contacts, so it loads as a deferred prop and is cached.
     *
     * @return array{count: int, days: int}|null
     */
    private function sunsetPreview(Client $client, SunsetPolicy $sunsetPolicy): ?array
    {
        $days = $client->sunset_inactive_days;

        if ($days === null) {
            return null;
        }

        $count = Cache::remember(
            SunsetPolicy::previewCacheKey($client->id, $days),
            600,
            fn (): int => $sunsetPolicy->candidateCount($client->id, now()->subDays($days)),
        );

        return ['count' => $count, 'days' => $days];
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
