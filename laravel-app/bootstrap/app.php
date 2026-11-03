<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['visitor' => \App\Http\Middleware\AuthenticateVisitor::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API errors are JSON even without "Accept: application/json" (Astro's fetch doesn't send it),
        // so a bad request returns 422 with a message instead of a redirect to an HTML page.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));
    })->create();
