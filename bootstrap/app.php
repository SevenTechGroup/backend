<?php

use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : '/',
        );

        // Middlewares transverses ajoutés en tête du groupe `api` (ordre significatif) :
        //  1. RequestIdMiddleware — résout/génère X-Request-ID en premier, afin que
        //     toutes les entrées de log (y compris CORS) portent la corrélation.
        //  2. CorsMiddleware — s'exécute avant `auth:api` pour qu'un préflight OPTIONS
        //     n'exige jamais de jeton.
        $middleware->api(prepend: [
            RequestIdMiddleware::class,
            CorsMiddleware::class,
        ]);

        // Alias appliqué uniquement à POST /api/reports (après `auth:api`).
        $middleware->alias([
            'idempotency' => IdempotencyMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
