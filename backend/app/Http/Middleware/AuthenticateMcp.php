<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Services\Mcp\McpIdentity;
use App\Services\OAuth\Endpoints;
use App\Services\OAuth\OAuthServer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may talk to the MCP endpoint.
 *
 * Two kinds of token are accepted and treated the same afterwards: a personal
 * key from User settings, and an access token from the OAuth flow. Both carry
 * their owner's permissions and nothing more.
 *
 * The 401 is the interesting part. It carries a `WWW-Authenticate` header
 * pointing at the resource metadata (RFC 9728) — that is how a client such as
 * Claude Desktop finds out there is an authorization server here at all. Answer
 * a missing token with a bare 401 and the connection stops rather than starting
 * the browser flow.
 */
class AuthenticateMcp
{
    public function __construct(private readonly OAuthServer $oauth) {}

    public function handle(Request $request, Closure $next): Response
    {
        // An installation that has switched MCP off should look like one that
        // never had it, rather than one refusing to talk.
        abort_unless(config('nextsearch.mcp.enabled'), 404);

        $plain = $request->bearerToken();

        if (! is_string($plain) || $plain === '') {
            return $this->challenge('The MCP endpoint needs an access token.');
        }

        $identity = $this->resolve($plain);

        if ($identity === null) {
            return $this->challenge('The token is unknown, expired or revoked.', 'invalid_token');
        }

        // Downstream code — the rate limiter among it — asks the request who is
        // calling. The tools get the identity itself, scopes included.
        $request->setUserResolver(fn () => $identity->user);
        app()->instance(McpIdentity::class, $identity);

        return $next($request);
    }

    private function resolve(string $plain): ?McpIdentity
    {
        if (str_starts_with($plain, ApiToken::PREFIX)) {
            /** @var ApiToken|null $token */
            $token = ApiToken::query()
                ->with('user')
                ->where('token_hash', ApiToken::hash($plain))
                ->first();

            if ($token === null || $token->hasExpired() || $token->user === null) {
                return null;
            }

            $token->touchLastUsed();

            return McpIdentity::fromApiKey($token->user, $token->name);
        }

        $token = $this->oauth->resolveAccessToken($plain);

        if ($token === null) {
            return null;
        }

        $token->touchLastUsed();

        return new McpIdentity(
            user: $token->user,
            scopes: $token->scopes ?? [],
            source: McpIdentity::SOURCE_OAUTH,
            clientName: $token->client?->name,
        );
    }

    private function challenge(string $description, string $error = 'invalid_request'): JsonResponse
    {
        return response()->json(
            ['error' => $error, 'error_description' => $description],
            Response::HTTP_UNAUTHORIZED,
            [
                'WWW-Authenticate' => sprintf(
                    'Bearer realm="%s", error="%s", error_description="%s", resource_metadata="%s"',
                    config('nextsearch.mcp.server_name'),
                    $error,
                    $description,
                    Endpoints::resourceMetadata(),
                ),
            ],
        );
    }
}
