<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth', function (Request $request): Limit {
            $key = strtolower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(10)->by($key);
        });

        // Signing is CPU-heavy: a tight per-IP budget protects the worker pool.
        RateLimiter::for('anonymous-signing', function (Request $request): Limit {
            return Limit::perMinute(20)->by($request->ip());
        });
    }
}
