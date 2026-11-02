<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Compliance\ConnectorRegistry;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(): a fresh instance per request and per queued job (safe under Octane/Horizon).
        $this->app->scoped(CurrentCompany::class);
        $this->app->singleton(ConnectorRegistry::class, fn () => new ConnectorRegistry(config('einvoicing')));
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
