<?php

namespace App\Http\Controllers\Auth;

use App\Actions\FindUserForGoogleSignIn;
use App\Exceptions\GoogleSignInException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\GoogleSignIn;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GoogleSignInController extends Controller
{
    public function __construct(private Socialite $socialite) {}

    /**
     * Send the user to Google. Socialite keeps the `state` value and the PKCE
     * verifier in the session, and the callback checks both.
     */
    public function redirect(): SymfonyRedirectResponse
    {
        abort_unless(GoogleSignIn::isEnabled(), 404);

        return $this->google()->redirect();
    }

    /**
     * Log in the user that Google sends back. A user with two-factor
     * authentication gets the Fortify challenge first, the same as after a
     * password.
     */
    public function callback(Request $request, FindUserForGoogleSignIn $findUser): RedirectResponse
    {
        abort_unless(GoogleSignIn::isEnabled(), 404);

        try {
            $user = $findUser->handle($this->googleUser());
        } catch (GoogleSignInException $exception) {
            return to_route('login')->with('google_error', $exception->getMessage());
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $this->twoFactorChallenge($request, $user);
        }

        Auth::guard(config('fortify.guard'))->login($user);

        $request->session()->regenerate();

        return redirect()->intended(Fortify::redirects('login'));
    }

    private function googleUser(): GoogleUser
    {
        try {
            /** @var GoogleUser */
            return $this->google()->user();
        } catch (InvalidStateException|GuzzleException $exception) {
            throw GoogleSignInException::incomplete();
        }
    }

    private function twoFactorChallenge(Request $request, User $user): RedirectResponse
    {
        $request->session()->put([
            'login.id' => $user->getKey(),
            'login.remember' => false,
        ]);

        TwoFactorAuthenticationChallenged::dispatch($user);

        return to_route('two-factor.login');
    }

    private function google(): AbstractProvider
    {
        /** @var AbstractProvider $provider */
        $provider = $this->socialite->driver('google');

        return $provider->enablePKCE();
    }
}
