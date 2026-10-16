<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session whose account was disabled after the sign-in. Keys and OAuth
 * tokens are refused where they are resolved; a session cookie is the one
 * credential that is already in the browser and has to be cut off here.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isDisabled()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(401, __('nextsearch.auth.account_disabled'));
        }

        return $next($request);
    }
}
