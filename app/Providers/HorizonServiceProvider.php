<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * The parent lets every request open Horizon when APP_ENV is local. A
     * self-hosted install can run with APP_ENV=local by mistake, so only the
     * gate decides, in every environment.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn (Request $request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Register the Horizon gate. Only the owner of the instance can open
     * Horizon, and only with a verified email.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null): bool => $user instanceof User
            && $user->isOwner()
            && $user->email_verified_at !== null);
    }
}
