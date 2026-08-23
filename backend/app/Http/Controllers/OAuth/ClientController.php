<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\OauthClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dynamic client registration, RFC 7591.
 *
 * An MCP client cannot be entered into a self-hosted installation by hand — the
 * person connecting it has no administrator rights, and often no idea what a
 * client id is. So it registers itself and receives one.
 *
 * That is open by design, and worth being clear about: anyone who can reach the
 * installation can create a client record. It buys nothing on its own. A client
 * without a user's consent has no token, and without a token it sees nothing.
 * The rate limit keeps the table from filling up; `MCP_OAUTH_DYNAMIC_REGISTRATION=false`
 * switches the endpoint off for installations that would rather not have it.
 */
class ClientController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        abort_unless(config('nextsearch.mcp.enabled') && config('nextsearch.mcp.oauth.enabled'), 404);
        abort_unless(config('nextsearch.mcp.oauth.dynamic_registration'), 403, 'Client registration is disabled.');

        $validated = $request->validate([
            'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => ['required', 'string', 'max:500'],
            'client_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'client_uri' => ['sometimes', 'nullable', 'string', 'max:500'],
            'logo_uri' => ['sometimes', 'nullable', 'string', 'max:500'],
            'token_endpoint_auth_method' => ['sometimes', 'string', 'in:none,client_secret_post,client_secret_basic'],
            'grant_types' => ['sometimes', 'array'],
            'grant_types.*' => ['string'],
            'response_types' => ['sometimes', 'array'],
            'response_types.*' => ['string'],
        ]);

        foreach ($validated['redirect_uris'] as $uri) {
            $this->assertAcceptableRedirect($uri);
        }

        // Everything else this server does not do. Saying so at registration is
        // friendlier than letting the client find out at the token endpoint.
        foreach ($validated['grant_types'] ?? [] as $grant) {
            if (! in_array($grant, ['authorization_code', 'refresh_token'], true)) {
                throw ValidationException::withMessages([
                    'grant_types' => "Grant type \"{$grant}\" is not supported.",
                ]);
            }
        }

        $method = $validated['token_endpoint_auth_method'] ?? OauthClient::AUTH_NONE;
        $secret = $method === OauthClient::AUTH_NONE ? null : 'nxs_secret_'.Str::random(40);

        $client = new OauthClient;
        $client->forceFill([
            'client_id' => OauthClient::newClientId(),
            'name' => $this->name($validated),
            'secret_hash' => $secret === null ? null : hash('sha256', $secret),
            'token_endpoint_auth_method' => $method,
            'redirect_uris' => array_values($validated['redirect_uris']),
            'client_uri' => $validated['client_uri'] ?? null,
            'logo_uri' => $validated['logo_uri'] ?? null,
            'is_dynamic' => true,
        ])->save();

        return response()->json(array_filter([
            'client_id' => $client->client_id,
            'client_id_issued_at' => $client->created_at->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $client->token_endpoint_auth_method,
            'client_secret' => $secret,
            // 0 means "does not expire" per RFC 7591.
            'client_secret_expires_at' => $secret === null ? null : 0,
        ], fn ($value) => $value !== null), Response::HTTP_CREATED);
    }

    /**
     * A callback has to be somewhere the browser can actually come back from,
     * and somewhere a stranger cannot steal a code to. https everywhere, http
     * only on the loopback interface — that is what a desktop client uses —
     * and private schemes for the ones that register their own.
     */
    private function assertAcceptableRedirect(string $uri): void
    {
        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['scheme'])) {
            throw ValidationException::withMessages([
                'redirect_uris' => "\"{$uri}\" is not a valid redirect URI.",
            ]);
        }

        // A fragment would be carried along on the redirect and could hide a
        // second destination. RFC 6749 forbids it outright.
        if (isset($parts['fragment'])) {
            throw ValidationException::withMessages([
                'redirect_uris' => "\"{$uri}\" must not contain a fragment.",
            ]);
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host'] ?? '');

        if ($scheme === 'https') {
            return;
        }

        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)) {
            return;
        }

        // Anything with a scheme of its own belongs to an installed
        // application; the operating system decides who receives it.
        if (! in_array($scheme, ['http', 'https'], true) && $scheme !== '') {
            return;
        }

        throw ValidationException::withMessages([
            'redirect_uris' => "\"{$uri}\" has to use https, or http on localhost.",
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function name(array $validated): string
    {
        $name = trim((string) ($validated['client_name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        // Nameless clients exist. The host of the callback is the most honest
        // thing left to put on a consent screen.
        $host = parse_url($validated['redirect_uris'][0], PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'Unnamed client';
    }
}
