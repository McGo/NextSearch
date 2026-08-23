<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\OauthClient;
use App\Services\OAuth\OAuthException;
use App\Services\OAuth\OAuthServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a code becomes a token, a token gets renewed, and either gets thrown
 * away again. No browser comes here — this is the client talking to us
 * directly.
 */
class TokenController extends Controller
{
    public function __construct(private readonly OAuthServer $server) {}

    public function issue(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        try {
            $client = $this->authenticateClient($request);
            $grantType = (string) $request->input('grant_type', '');

            $body = match ($grantType) {
                'authorization_code' => $this->server->exchangeAuthorizationCode(
                    $client,
                    $this->required($request, 'code'),
                    $this->required($request, 'redirect_uri'),
                    $this->required($request, 'code_verifier'),
                ),
                'refresh_token' => $this->server->refresh(
                    $client,
                    $this->required($request, 'refresh_token'),
                ),
                default => throw OAuthException::unsupportedGrantType($grantType),
            };
        } catch (OAuthException $e) {
            return $this->noStore(response()->json($e->payload(), $e->status));
        }

        return $this->noStore(response()->json($body));
    }

    /**
     * RFC 7009. A token that is gone, unknown or never existed all end the same
     * way — 200 and nothing to say. Anything else would turn the endpoint into
     * a way of asking whether a given string is a valid token.
     */
    public function revoke(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        try {
            $client = $this->authenticateClient($request);
        } catch (OAuthException $e) {
            return $this->noStore(response()->json($e->payload(), $e->status));
        }

        $token = (string) $request->input('token', '');

        if ($token !== '') {
            $this->server->revoke($client, $token);
        }

        return $this->noStore(response()->json(new \stdClass));
    }

    /**
     * The client id comes either in the body or, for a client with a secret, as
     * HTTP Basic — RFC 6749 allows both and clients disagree about which.
     */
    private function authenticateClient(Request $request): OauthClient
    {
        $clientId = (string) ($request->input('client_id') ?? $request->getUser() ?? '');
        $secret = $request->input('client_secret') ?? $request->getPassword();

        if ($clientId === '') {
            throw OAuthException::invalidClient('No client_id given.');
        }

        /** @var OauthClient|null $client */
        $client = OauthClient::query()->where('client_id', $clientId)->first();

        if ($client === null) {
            throw OAuthException::invalidClient('Unknown client.');
        }

        // A public client proves itself with PKCE alone; a secret it sent
        // anyway is not held against it, but it does not help either.
        if (! $client->isPublic() && ! $client->secretMatches(is_string($secret) ? $secret : null)) {
            throw OAuthException::invalidClient('The client secret is wrong.');
        }

        return $client;
    }

    private function required(Request $request, string $field): string
    {
        $value = $request->input($field);

        if (! is_string($value) || $value === '') {
            throw OAuthException::invalidRequest("The parameter \"{$field}\" is missing.");
        }

        return $value;
    }

    /**
     * Tokens have no business in a proxy cache.
     */
    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    private function ensureEnabled(): void
    {
        abort_unless(
            config('nextsearch.mcp.enabled') && config('nextsearch.mcp.oauth.enabled'),
            404,
        );
    }
}
