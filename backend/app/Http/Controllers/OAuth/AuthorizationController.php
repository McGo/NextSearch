<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\OauthAccessToken;
use App\Models\OauthClient;
use App\Services\OAuth\AuthorizationError;
use App\Services\OAuth\Endpoints;
use App\Services\OAuth\OAuthServer;
use App\Services\OAuth\Scopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The consent step. Unlike the rest of the OAuth endpoints this one runs on the
 * session: a person is standing in front of it, signed in to NextSearch, and
 * decides whether an application may act on their behalf.
 *
 * The screen itself is a page of the interface (`/oauth/authorize`). This
 * controller only answers it: `show` says what is being asked for, `approve`
 * and `deny` say where to send the browser next.
 */
class AuthorizationController extends Controller
{
    public function __construct(private readonly OAuthServer $server) {}

    /**
     * What the consent page needs to draw itself. Nothing is decided here and
     * nothing is issued.
     */
    public function show(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        try {
            $parsed = $this->parse($request);
        } catch (AuthorizationError $e) {
            return $this->renderError($e);
        }

        /** @var OauthClient $client */
        $client = $parsed['client'];
        $user = $request->user();

        $known = OauthAccessToken::query()
            ->where('user_id', $user->id)
            ->where('oauth_client_id', $client->id)
            ->whereNull('revoked_at')
            ->exists();

        return response()->json([
            'client' => [
                'name' => $client->name,
                'uri' => $client->client_uri,
                'logo' => $client->logo_uri,
                // Where the code goes. Worth showing: it is the one part of
                // the request a user can actually judge.
                'redirect_host' => parse_url($parsed['redirect_uri'], PHP_URL_HOST) ?: $parsed['redirect_uri'],
                'registered_at' => $client->created_at?->toIso8601String(),
                'known' => $known,
            ],
            'scopes' => array_map(fn (string $scope) => [
                'id' => $scope,
                // Reading is what the connection is for; without it there is
                // nothing to approve. Writing is the part that is optional.
                'required' => $scope === Scopes::DOCUMENTS_READ,
            ], $parsed['scopes']),
            'resource' => $parsed['resource'],
        ]);
    }

    /**
     * The user said yes. Issues the code and hands back where to send the
     * browser — the page redirects, so that the call itself stays a normal
     * same-origin request with its session and CSRF token.
     */
    public function approve(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        try {
            $parsed = $this->parse($request);
        } catch (AuthorizationError $e) {
            return $this->renderError($e);
        }

        // What was ticked, narrowed to what was asked for. Reading always
        // stays — a connection that may see nothing is not worth having.
        $granted = array_values(array_unique(array_merge(
            [Scopes::DOCUMENTS_READ],
            array_intersect(
                $parsed['scopes'],
                array_filter((array) $request->input('scopes', []), 'is_string'),
            ),
        )));

        $code = $this->server->issueAuthorizationCode(
            client: $parsed['client'],
            user: $request->user(),
            redirectUri: $parsed['redirect_uri'],
            scopes: $granted,
            challenge: $parsed['code_challenge'],
            resource: $parsed['resource'],
        );

        $query = array_filter([
            'code' => $code,
            'state' => $parsed['state'],
        ], fn ($value) => $value !== null && $value !== '');

        $separator = str_contains($parsed['redirect_uri'], '?') ? '&' : '?';

        return response()->json([
            'redirect_to' => $parsed['redirect_uri'].$separator.http_build_query($query),
        ]);
    }

    /**
     * The user said no. The client is told so at its callback rather than left
     * waiting for a code that will never come.
     */
    public function deny(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        try {
            $parsed = $this->parse($request);
        } catch (AuthorizationError $e) {
            return $this->renderError($e);
        }

        $error = AuthorizationError::redirect(
            $parsed['redirect_uri'],
            'access_denied',
            'The request was declined.',
            $parsed['state'],
        );

        return response()->json(['redirect_to' => $error->redirectTo]);
    }

    /**
     * Reads the authorization request and checks it, in the order the checks
     * have to happen: client first, then callback, then everything else. Only
     * once those two hold may an error be sent to the callback at all.
     *
     * @return array{client: OauthClient, redirect_uri: string, scopes: list<string>, state: ?string, code_challenge: string, resource: ?string}
     */
    private function parse(Request $request): array
    {
        $clientId = (string) $request->input('client_id', '');

        /** @var OauthClient|null $client */
        $client = $clientId === ''
            ? null
            : OauthClient::query()->where('client_id', $clientId)->first();

        if ($client === null) {
            throw AuthorizationError::fatal('This application is not registered with NextSearch.');
        }

        $redirectUri = (string) $request->input('redirect_uri', '');

        if ($redirectUri === '' || ! $client->allowsRedirect($redirectUri)) {
            throw AuthorizationError::fatal(
                'The application asked to be sent back to an address it never registered. '
                .'Nothing was granted.'
            );
        }

        $state = $request->input('state');
        $state = is_string($state) ? $state : null;

        if ((string) $request->input('response_type', '') !== 'code') {
            throw AuthorizationError::redirect(
                $redirectUri,
                'unsupported_response_type',
                'This server only issues authorization codes.',
                $state,
            );
        }

        $challenge = (string) $request->input('code_challenge', '');
        $method = (string) $request->input('code_challenge_method', '');

        // PKCE is not optional here, and neither is S256. A public client
        // without it would hand its code to whoever intercepts the redirect.
        if ($challenge === '' || $method !== 'S256') {
            throw AuthorizationError::redirect(
                $redirectUri,
                'invalid_request',
                'A code_challenge with code_challenge_method=S256 is required.',
                $state,
            );
        }

        $resource = $request->input('resource');
        $resource = is_string($resource) && $resource !== '' ? $resource : null;

        // RFC 8707. Our tokens are opaque and only we accept them, so this is
        // a sanity check, not a security boundary: a client asking for a token
        // for someone else's server has the wrong address.
        if ($resource !== null && parse_url($resource, PHP_URL_HOST) !== parse_url(Endpoints::resource(), PHP_URL_HOST)) {
            throw AuthorizationError::redirect(
                $redirectUri,
                'invalid_target',
                'The requested resource does not belong to this installation.',
                $state,
            );
        }

        return [
            'client' => $client,
            'redirect_uri' => $redirectUri,
            'scopes' => Scopes::parse($request->input('scope')),
            'state' => $state,
            'code_challenge' => $challenge,
            'resource' => $resource,
        ];
    }

    private function renderError(AuthorizationError $error): JsonResponse
    {
        // A redirectable error is not a failure of this request — the page
        // needs to send the browser on, so the client learns what happened.
        if ($error->redirectTo !== null) {
            return response()->json([
                'message' => $error->getMessage(),
                'redirect_to' => $error->redirectTo,
            ]);
        }

        return response()->json(['message' => $error->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function ensureEnabled(): void
    {
        abort_unless(
            config('nextsearch.mcp.enabled') && config('nextsearch.mcp.oauth.enabled'),
            404,
        );
    }
}
