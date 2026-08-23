<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Services\OAuth\Endpoints;
use App\Services\OAuth\Scopes;
use Illuminate\Http\JsonResponse;

/**
 * The two discovery documents an MCP client reads before it does anything else.
 *
 * Both are public — they hold no secrets, and a client has to be able to fetch
 * them precisely because it has no token yet. The chain runs: 401 from the MCP
 * endpoint names the resource metadata, the resource metadata names the
 * authorization server, its metadata names the endpoints.
 */
class MetadataController extends Controller
{
    /**
     * RFC 9728. Describes the MCP endpoint as a protected resource and says
     * who issues tokens for it.
     */
    public function protectedResource(): JsonResponse
    {
        $this->ensureEnabled();

        return response()->json([
            'resource' => Endpoints::resource(),
            'authorization_servers' => [Endpoints::issuer()],
            'scopes_supported' => Scopes::all(),
            'bearer_methods_supported' => ['header'],
            'resource_name' => (string) config('nextsearch.mcp.server_name'),
            'resource_documentation' => Endpoints::documentation(),
        ]);
    }

    /**
     * RFC 8414. What this authorization server can do — and by omission, what
     * it cannot: no implicit grant, no password grant, no `plain` PKCE.
     */
    public function authorizationServer(): JsonResponse
    {
        $this->ensureEnabled();

        return response()->json(array_filter([
            'issuer' => Endpoints::issuer(),
            'authorization_endpoint' => Endpoints::authorization(),
            'token_endpoint' => Endpoints::token(),
            'revocation_endpoint' => Endpoints::revocation(),
            'registration_endpoint' => config('nextsearch.mcp.oauth.dynamic_registration')
                ? Endpoints::registration()
                : null,
            'scopes_supported' => Scopes::all(),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            // S256 only. PKCE is required of every client, public or not.
            'code_challenge_methods_supported' => ['S256'],
            'service_documentation' => Endpoints::documentation(),
        ], fn ($value) => $value !== null));
    }

    private function ensureEnabled(): void
    {
        abort_unless(
            config('nextsearch.mcp.enabled') && config('nextsearch.mcp.oauth.enabled'),
            404,
        );
    }
}
