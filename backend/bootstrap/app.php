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

        // Reine API: nicht angemeldete Anfragen gehören mit 401 beantwortet,
        // nicht auf eine Login-Seite umgeleitet. Ohne dieses `null` ruft die
        // Auth-Middleware route('login') auf — die es hier nicht gibt — und
        // wirft, sobald der Nitro-Proxy den Accept-Header nicht durchreicht.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Alles rendert als JSON — unabhängig davon, welchen Accept-Header der
        // Proxy weiterreicht.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
    })->create();
