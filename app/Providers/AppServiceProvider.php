<?php

namespace App\Providers;

use App\Contracts\AssetStorage;
use App\Models\Assignment;
use App\Models\Notification;
use App\Models\Report;
use App\Policies\AssignmentPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\ReportPolicy;
use App\Services\CloudinaryAssetStorage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AssetStorage::class, CloudinaryAssetStorage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Report::class, ReportPolicy::class);
        Gate::policy(Assignment::class, AssignmentPolicy::class);
        Gate::policy(Notification::class, NotificationPolicy::class);
    }
}
