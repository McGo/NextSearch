<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Die API läuft bewusst über den `web`-Stack: Nuxt reicht /api per Nitro
        // an dieses Backend durch, UI und API teilen also eine Origin. Damit
        // greifen Session-Cookie und CSRF-Schutz ohne weitere Konfiguration.
        web: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Der Nuxt-Container ist der einzige Aufrufer und steht im selben Netz.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
    })->create();
