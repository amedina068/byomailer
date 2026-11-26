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
 *
 * The page shows the form only for the correct setup token. Without the token,
 * it tells the operator where to find the setup URL. After the first user
 * exists, GET sends the visitor to the login page and POST returns 404.
 */
class SetupController extends Controller
{
    /**
     * The commands that print the setup URL, one for each type of install.
     */
    private const SETUP_URL_COMMANDS = [
        ['label' => 'Docker', 'command' => 'docker logs byomailer'],
        ['label' => 'Docker Compose', 'command' => 'docker compose logs web'],
        ['label' => 'Any install', 'command' => 'php artisan byomailer:setup-url'],
    ];

    public function show(Request $request): Response|RedirectResponse
    {
        if (! InstanceSetup::isNeeded()) {
            return redirect('/login')->with('status', 'This instance is already set up. Log in with your account.');
        }

        $token = $this->tokenFrom($request->query('token'));
        $tokenIsValid = InstanceSetup::tokenMatches($token);

        return Inertia::render('auth/Setup', [
            // A wrong token never goes back to the page.
            'token' => $tokenIsValid ? $token : '',
            'tokenIsValid' => $tokenIsValid,
            'tokenWasWrong' => ! $tokenIsValid && $token !== '',
            'setupUrlCommands' => self::SETUP_URL_COMMANDS,
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
                'token' => 'The setup token is not correct. Copy the setup URL from the log of the container, or run php artisan byomailer:setup-url.',
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

    /**
     * Reads the token from the query. An operator can paste the full setup URL
     * into the token field, so the method also reads the token from a URL.
     */
    private function tokenFrom(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if (str_contains($value, 'token=')) {
            parse_str((string) parse_url($value, PHP_URL_QUERY), $query);
            $value = is_string($query['token'] ?? null) ? $query['token'] : '';
        }

        return $value;
    }
}
