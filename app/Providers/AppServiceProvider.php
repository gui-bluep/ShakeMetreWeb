<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->configureRateLimiting();
    }

    /**
     * The SSO endpoints are rate limited because the token in the consume URL is the only
     * secret protecting a login, and issuing tickets is the strongest thing the API can do.
     *
     * Issuing is keyed on the machine token rather than the IP: ShakeDesign calls from one
     * server, so an IP limit would be a single shared bucket for every user.
     *
     * Consumption is keyed on the IP and set well above normal use - a legitimate visitor
     * follows one link once - so it blunts brute-forcing without breaking a user who reloads.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('sso-issue', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('sso-consume', fn (Request $request) => Limit::perMinute(20)
            ->by($request->ip()));
    }
}
