<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Compliance\ConnectorRegistry;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Support\ClientAddress;
use App\Support\StateMachine;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use App\Support\ProductionChecks;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * API requests a minute per signed-in session. The overview alone makes 17 (six of them the income chart's
     * months) and signing in onto it 34, so 120 ran out for someone simply moving around the app. 300 is about
     * fifteen pages a minute, more than a person reads, and still five a second for anyone scripting the API.
     */
    public const API_REQUESTS_PER_MINUTE = 300;

    public function register(): void
    {
        // scoped(): a fresh instance per request and per queued job (safe under Octane/Horizon).
        $this->app->scoped(CurrentCompany::class);
        $this->app->singleton(StateMachine::class, fn () => StateMachine::fromConfig()); // read-only: safe to share
        $this->app->singleton(ConnectorRegistry::class, fn () => new ConnectorRegistry(config('einvoicing')));
    }

    public function boot(): void
    {
        if ($this->app->isProduction()) {
            ProductionChecks::assert($this->app);   // S9: no debug, no owner password, an app key
        }
        Model::shouldBeStrict(! $this->app->isProduction());

        // One password rule for sign-up, reset and change. The leaked-password check asks an outside service
        // (k-anonymity: only five characters of the hash leave the server); tests do not call out.
        Password::defaults(fn () => $this->app->environment('testing')
            ? Password::min(10)
            : Password::min(10)->uncompromised());

        // Named limiters, each with its own counter. (Two unnamed `throttle:n,1` middlewares on one route share
        // a counter and count every request twice: the sign-in limit of 10 a minute was really 5.)
        // Counted per signed-in user, otherwise per visitor address (ClientAddress), never per web server. A shared
        // demo account (config/demo.php) is counted per demo sign-in, so one visitor cannot use up the others' share.
        RateLimiter::for('api', function (Request $request) {
            $token = $request->user()?->currentAccessToken();
            // The demo's sample books are written by the server itself (DemoSetup, FillDemoData) with a short-lived token
            // that never leaves the server; those requests are not a visitor's and are not counted.
            if ($token !== null && ($token->name ?? null) === 'demo-setup') {
                return Limit::none();
            }
            $key = $token !== null && ($token->name ?? null) === 'demo'
                ? 'demo-token:' . $token->getKey()
                : ($request->user()?->getAuthIdentifier() ?? ClientAddress::of($request));

            return Limit::perMinute(self::API_REQUESTS_PER_MINUTE)->by('api|' . $key);
        });
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)
            ->by('auth|' . ClientAddress::of($request)));
    }
}
