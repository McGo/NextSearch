<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the response language from the X-Locale header the frontend sends (it
 * mirrors the chosen UI language), falling back to Accept-Language. Only the
 * languages the product ships are accepted; anything else stays on the default.
 */
class SetLocale
{
    /** @var list<string> */
    private const SUPPORTED = ['en', 'de'];

    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->header('X-Locale')
            ?? $request->getPreferredLanguage(self::SUPPORTED);

        if (is_string($requested) && in_array($requested, self::SUPPORTED, true)) {
            App::setLocale($requested);
        }

        return $next($request);
    }
}
