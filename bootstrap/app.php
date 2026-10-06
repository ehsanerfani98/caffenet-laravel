<?php

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
        // Helmet-equivalent security headers on every response.
        $middleware->append(App\Http\Middleware\SecurityHeaders::class);

        // Origin-based CSRF guard for the stateless API surface.
        $middleware->group('api', [
            App\Http\Middleware\OriginGuard::class,
        ]);

        // Route middleware aliases.
        $middleware->alias([
            'auth.session' => App\Http\Middleware\EnsureAuth::class,
            'auth.optional' => App\Http\Middleware\OptionalAuth::class,
            'permission' => App\Http\Middleware\EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Never leak internal errors to the client — fixed Persian message,
        // mirroring errorHandler in middleware.ts.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    return response()->json([
                        'error' => $e->getMessage() ?: 'خطای پردازش درخواست.',
                    ], $e->getStatusCode());
                }

                report($e);

                return response()->json(['error' => 'خطای داخلی سرور. لطفاً بعداً تلاش کنید.'], 500);
            }

            return null;
        });
    })->create();
