<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // ESP32 devices poll frequently (heartbeat/enrollment status), so this is
        // generous per-device throughput rather than a login-style guard.
        RateLimiter::for('hardware-api', function ($request) {
            $key = $request->header('X-Device-Token') ?: $request->bearerToken() ?: $request->ip();

            return Limit::perMinute(120)->by($key);
        });
    }
}
