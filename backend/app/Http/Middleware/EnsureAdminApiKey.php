<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door to /api/v1/admin. An administrator's ordinary key does not open it:
 * the key has to carry the admin flag, set deliberately when it was made, and
 * its owner has to still be an administrator.
 */
class EnsureAdminApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->attributes->get(ApiToken::class) ?? $this->tokenFromHeader($request);

        abort_unless(
            $token instanceof ApiToken && $token->grantsAdminApi(),
            403,
            __('nextsearch.api.admin_key_required'),
        );

        return $next($request);
    }

    /**
     * The guard leaves the key on the request when it resolves the user. Should
     * the user have been resolved some other way first, look the key up again
     * rather than guess.
     */
    private function tokenFromHeader(Request $request): ?ApiToken
    {
        $plain = $request->bearerToken();

        if (! is_string($plain) || $plain === '') {
            return null;
        }

        return ApiToken::query()
            ->with('user')
            ->where('token_hash', ApiToken::hash($plain))
            ->where('user_id', $request->user()?->id)
            ->first();
    }
}
