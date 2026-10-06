<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Exceptions\UnauthorizedException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The SPA and the API share the same origin and the same session
        // cookie (Fortify session auth), so the /api/* surface runs inside
        // the web middleware group (sessions + CSRF), rate-limited 300/min
        // per IP and guarded against foreign origins — matching the original
        // cookie-based architecture.
        then: function (): void {
            Route::middleware(['web', 'throttle:300,1', App\Http\Middleware\OriginGuard::class])
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Helmet-equivalent security headers on every response.
        $middleware->append(App\Http\Middleware\SecurityHeaders::class);

        // Route middleware aliases.
        $middleware->alias([
            'auth.session' => App\Http\Middleware\EnsureAuth::class,
            'auth.optional' => App\Http\Middleware\OptionalAuth::class,
            // Roles & permissions are checked exclusively through spatie.
            'permission' => Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Validation errors keep the flat `{error}` contract (400).
        $exceptions->render(function (Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                $message = collect($e->errors())->flatten()->first() ?: 'داده‌های ارسالی معتبر نیست.';

                return response()->json(['error' => $message], 400);
            }

            return null;
        });

        // spatie's permission middleware rejection keeps the original
        // Persian message and 403 status.
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['error' => 'شما دسترسی لازم برای این عملیات را ندارید.'], 403);
            }

            return null;
        });

        // Never leak internal errors to the client — fixed Persian message,
        // mirroring errorHandler in middleware.ts.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                // Pre-built responses (e.g. from Fortify's OTP callback) must
                // fall through to the framework so their JSON ships as-is.
                if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                    return null;
                }

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
