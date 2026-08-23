<?php

use App\Http\Middleware\AuthenticateMcp;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // The API deliberately runs on the `web` stack: Nuxt forwards /api via
        // Nitro to this backend, so the UI and the API share an origin. That way
        // the session cookie and CSRF protection work without extra config.
        web: __DIR__.'/../routes/api.php',
        // The public API for personal keys. Stateless on purpose: no session,
        // no CSRF token, authentication from the Authorization header.
        api: __DIR__.'/../routes/v1.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        // The MCP server and its OAuth endpoints. Stateless like the public
        // API, but with paths of their own — two of them have to sit at the
        // root of the domain, where a prefix would put them out of reach.
        then: fn () => Route::middleware('api')->group(__DIR__.'/../routes/mcp.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The Nuxt container is the only caller and sits on the same network.
        $middleware->trustProxies(at: '*');

        // Set the response language from the header the frontend sends. The
        // public API gets it too — an error message there should read in the
        // caller's language as well.
        $middleware->web(append: [SetLocale::class]);
        $middleware->api(append: [SetLocale::class]);

        // Pure API: unauthenticated requests belong answered with 401, not
        // redirected to a login page. Without this `null` the auth middleware
        // calls route('login') — which doesn't exist here — and throws as soon
        // as the Nitro proxy drops the Accept header.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            // A personal key or an OAuth token, and the 401 that tells an MCP
            // client where to fetch one.
            'auth.mcp' => AuthenticateMcp::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Everything renders as JSON — regardless of the Accept header the proxy
        // passes on.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
    })->create();
