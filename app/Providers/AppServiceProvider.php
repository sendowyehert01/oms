<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
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
        Model::preventLazyLoading(! $this->app->isProduction());
        Gate::define('manage-staff', fn (User $user): bool => $user->isAdmin());
        Gate::define('export-data', fn (User $user): bool => $user->isAdmin());
        Gate::define('view-audit-logs', fn (User $user): bool => $user->isAdmin());
        Gate::define('view-financials', fn (User $user): bool => ! $user->isProductionMonitor());
    }
}
