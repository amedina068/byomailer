<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Instance\LoginLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Logs in with a one-time link from `byomailer:install`.
 *
 * The link has a relative signature and a nonce that works one time. A link
 * that is not valid sends the person to the login page with a message.
 */
class LoginLinkController extends Controller
{
    public function __invoke(Request $request, int $user, string $nonce): RedirectResponse
    {
        if (! $request->hasValidSignature(absolute: false)) {
            return $this->refuse();
        }

        $account = LoginLink::consume($user, $nonce);

        if (! $account instanceof User || $account->isDeactivated()) {
            return $this->refuse();
        }

        Auth::login($account);
        $request->session()->regenerate();

        return redirect(config('fortify.home'));
    }

    private function refuse(): RedirectResponse
    {
        return redirect('/login')->withErrors([
            'email' => 'This login link expired or was used. Run php artisan byomailer:install --json on the server to get a new link.',
        ]);
    }
}
