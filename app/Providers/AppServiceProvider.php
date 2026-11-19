<?php

namespace App\Providers;

use App\Services\Jira;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request, so each Jira issue is fetched only once.
        $this->app->singleton(Jira::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Login attempts: 5 a minute per email and address.
        RateLimiter::for('login', fn(Request $request) => Limit::perMinute(5)->by(
            mb_strtolower((string) $request->input('email')) . '|' . $request->ip()
        ));
    }
}
