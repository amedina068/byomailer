<?php

namespace App\Http\Controllers;

use App\Actions\Instance\CreateInstanceOwner;
use App\Support\InstanceSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first-run page of a self-hosted instance. It creates the owner account.
 * After the first user exists, both routes return 404.
 */
class SetupController extends Controller
{
    public function show(Request $request): Response
    {
        abort_unless(InstanceSetup::isNeeded(), 404);

        return Inertia::render('auth/Setup', [
            'token' => (string) $request->query('token', ''),
            'setupUrlCommand' => 'php artisan byomailer:setup-url',
        ]);
    }

    public function store(Request $request, CreateInstanceOwner $owners): RedirectResponse
    {
        abort_unless(InstanceSetup::isNeeded(), 404);

        $input = $request->validate([
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed'],
            'timezone' => ['nullable', 'string'],
        ]);

        if (! InstanceSetup::tokenMatches($input['token'])) {
            throw ValidationException::withMessages([
                'token' => 'The setup token is not correct. Copy the setup URL from the log of the web container, or run php artisan byomailer:setup-url.',
            ]);
        }

        $owner = $owners->createFirstOwner([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'password_confirmation' => (string) $request->input('password_confirmation'),
            'timezone' => $input['timezone'] ?? null,
        ]);

        // Another request created the first account while this one waited.
        abort_if($owner === null, 404);

        Auth::login($owner);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
