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
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(): a fresh instance per request and per queued job (safe under Octane/Horizon).
        $this->app->scoped(CurrentCompany::class);
        $this->app->singleton(StateMachine::class, fn () => StateMachine::fromConfig()); // read-only: safe to share
        $this->app->singleton(ConnectorRegistry::class, fn () => new ConnectorRegistry(config('einvoicing')));
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // One password rule for sign-up, reset and change. The leaked-password check asks an outside service
        // (k-anonymity: only five characters of the hash leave the server); tests do not call out.
        Password::defaults(fn () => $this->app->environment('testing')
            ? Password::min(10)
            : Password::min(10)->uncompromised());

        // Named limiters, each with its own counter. (Two unnamed `throttle:n,1` middlewares on one route share
        // a counter and count every request twice: the sign-in limit of 10 a minute was really 5.)
        // Counted per signed-in user, otherwise per visitor address (ClientAddress), never per web server.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by('api|' . ($request->user()?->getAuthIdentifier() ?? ClientAddress::of($request))));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)
            ->by('auth|' . ClientAddress::of($request)));
    }
}
