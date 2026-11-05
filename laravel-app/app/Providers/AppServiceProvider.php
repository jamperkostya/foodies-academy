<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
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
        // "php artisan serve" (the dev container) hands its workers only the variables of .env and
        // a short allow-list; these come from docker-compose instead.
        ServeCommand::$passthroughVariables = [...ServeCommand::$passthroughVariables, 'FOODIES_API_SECRET', 'WP_INTERNAL_URL'];
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Requests a minute per visitor (IP), counted separately for each action: plain
        // "throttle:5,1" shares one counter per IP across all routes, so a few likes
        // would block subscribing or posting a review.
        $limits = [
            'comments' => 5,
            'likes' => 20,
            'views' => 60,
            'ratings' => 20,
            'search-log' => 30,
            'subscribe' => 5,
            'register' => 5,
            'login' => 10,
            'account' => 120,
        ];
        foreach ($limits as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by($request->ip()));
        }
    }
}
