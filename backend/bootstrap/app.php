<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // The API deliberately runs on the `web` stack: Nuxt forwards /api via
        // Nitro to this backend, so the UI and the API share an origin. That way
        // the session cookie and CSRF protection work without extra config.
        web: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The Nuxt container is the only caller and sits on the same network.
        $middleware->trustProxies(at: '*');

        // Set the response language from the header the frontend sends.
        $middleware->web(append: [SetLocale::class]);

        // Pure API: unauthenticated requests belong answered with 401, not
        // redirected to a login page. Without this `null` the auth middleware
        // calls route('login') — which doesn't exist here — and throws as soon
        // as the Nitro proxy drops the Accept header.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Everything renders as JSON — regardless of the Accept header the proxy
        // passes on.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
    })->create();
