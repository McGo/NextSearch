<?php

namespace App\Services\OAuth;

use App\Models\OauthAccessToken;
use App\Models\OauthAuthorizationCode;
use App\Models\OauthClient;
use App\Models\OauthRefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The authorization server in front of the MCP endpoint. Authorization code
 * with PKCE and refresh tokens — nothing else. No implicit grant, no password
 * grant, no `plain` challenge method.
 *
 * The flow is the one every MCP client walks: it registers itself, sends the
 * user to /oauth/authorize in a browser, receives a code at its own callback
 * and trades that for a token here.
 */
class OAuthServer
{
    private const CODE_PREFIX = 'nxa_';

    private const RANDOM_LENGTH = 48;

    /**
     * Notes the user's decision and returns the code to send back to the
     * client. Everything the exchange has to check is nailed down here: the
     * client, the redirect URI and the PKCE challenge.
     *
     * @param  list<string>  $scopes
     */
    public function issueAuthorizationCode(
        OauthClient $client,
        User $user,
        string $redirectUri,
        array $scopes,
        string $challenge,
        ?string $resource = null,
    ): string {
        $plain = self::CODE_PREFIX.Str::random(self::RANDOM_LENGTH);

        $code = new OauthAuthorizationCode;
        $code->forceFill([
            'code_hash' => hash('sha256', $plain),
            'oauth_client_id' => $client->id,
            'user_id' => $user->id,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => $resource,
            'expires_at' => now()->addSeconds((int) config('nextsearch.mcp.oauth.authorization_code_ttl_seconds')),
        ])->save();

        return $plain;
    }

    /**
     * Trades a code for a token pair.
     *
     * A code that has already been used is not merely refused: everything
     * issued from it goes too. Either the client is broken or someone else got
     * hold of the code, and neither case should leave a working token behind.
     *
     * @return array<string, mixed> the token endpoint's response body
     */
    public function exchangeAuthorizationCode(
        OauthClient $client,
        string $code,
        string $redirectUri,
        string $verifier,
    ): array {
        /** @var OauthAuthorizationCode|null $record */
        $record = OauthAuthorizationCode::query()
            ->where('code_hash', hash('sha256', $code))
            ->first();

        if ($record === null) {
            throw OAuthException::invalidGrant('The authorization code is unknown.');
        }

        if ($record->used_at !== null) {
            $this->revokeIssuedFor($record);

            throw OAuthException::invalidGrant('This authorization code was already used.');
        }

        if (! $record->isUsable()) {
            throw OAuthException::invalidGrant('The authorization code has expired.');
        }

        if ($record->oauth_client_id !== $client->id) {
            throw OAuthException::invalidGrant('The authorization code was issued to another client.');
        }

        // Exact match, as at the start of the flow — otherwise a code could be
        // redirected somewhere else on the second leg.
        if (! hash_equals($record->redirect_uri, $redirectUri)) {
            throw OAuthException::invalidGrant('The redirect URI does not match the one from the authorization request.');
        }

        if (! $record->verifierMatches($verifier)) {
            throw OAuthException::invalidGrant('The code verifier does not match the challenge.');
        }

        $user = $record->user;

        if ($user === null || $user->isDisabled()) {
            throw OAuthException::invalidGrant('The account behind this code no longer exists or is disabled.');
        }

        return DB::transaction(function () use ($record, $client, $user) {
            $record->forceFill(['used_at' => now()])->save();

            return $this->issueTokenPair($client, $user, $record->scopes ?? []);
        });
    }

    /**
     * Rotation: the refresh token presented is spent, and a fresh pair takes
     * its place. A second attempt with the old one lands in the replay branch
     * below and takes the whole connection down — that is what makes a stolen
     * refresh token noticeable.
     *
     * @return array<string, mixed>
     */
    public function refresh(OauthClient $client, string $refreshToken): array
    {
        /** @var OauthRefreshToken|null $record */
        $record = OauthRefreshToken::query()
            ->where('token_hash', OauthRefreshToken::hash($refreshToken))
            ->first();

        if ($record === null) {
            throw OAuthException::invalidGrant('The refresh token is unknown.');
        }

        if ($record->oauth_client_id !== $client->id) {
            throw OAuthException::invalidGrant('The refresh token was issued to another client.');
        }

        if ($record->revoked_at !== null) {
            // Someone is using a token that was already rotated away. Both the
            // legitimate client and whoever else has it now start over.
            $this->revokeConnectionFor($record->user_id, $record->oauth_client_id);

            throw OAuthException::invalidGrant('This refresh token was already used.');
        }

        if (! $record->isUsable()) {
            throw OAuthException::invalidGrant('The refresh token has expired.');
        }

        $user = $record->user;

        if ($user === null || $user->isDisabled()) {
            throw OAuthException::invalidGrant('The account behind this token no longer exists or is disabled.');
        }

        return DB::transaction(function () use ($record, $client, $user) {
            $record->forceFill(['revoked_at' => now()])->save();
            $record->accessToken?->forceFill(['revoked_at' => now()])->save();

            return $this->issueTokenPair($client, $user, $record->scopes ?? []);
        });
    }

    /**
     * RFC 7009. Takes either kind of token; a refresh token drags its access
     * token along. An unknown token is not an error — the endpoint's job is to
     * make sure the thing is dead afterwards, and it already is.
     */
    public function revoke(OauthClient $client, string $token): void
    {
        $access = OauthAccessToken::query()
            ->where('token_hash', OauthAccessToken::hash($token))
            ->where('oauth_client_id', $client->id)
            ->first();

        if ($access !== null) {
            $access->forceFill(['revoked_at' => now()])->save();
            OauthRefreshToken::query()
                ->where('oauth_access_token_id', $access->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return;
        }

        $refresh = OauthRefreshToken::query()
            ->where('token_hash', OauthRefreshToken::hash($token))
            ->where('oauth_client_id', $client->id)
            ->first();

        if ($refresh !== null) {
            $refresh->forceFill(['revoked_at' => now()])->save();
            $refresh->accessToken?->forceFill(['revoked_at' => now()])->save();
        }
    }

    /**
     * The access token behind a bearer string, or null. Expired and revoked
     * ones count as absent.
     */
    public function resolveAccessToken(string $plain): ?OauthAccessToken
    {
        /** @var OauthAccessToken|null $token */
        $token = OauthAccessToken::query()
            ->with(['user', 'client'])
            ->where('token_hash', OauthAccessToken::hash($plain))
            ->first();

        if ($token === null || ! $token->isUsable() || $token->user === null || $token->user->isDisabled()) {
            return null;
        }

        return $token;
    }

    /**
     * The connections one user has running, for the list in their profile.
     *
     * A connection counts as alive while it holds a refresh token that has not
     * been revoked — the access token beside it lives an hour and says nothing
     * about whether the client is still connected.
     *
     * @return list<array<string, mixed>>
     */
    public function connectionsFor(User $user): array
    {
        $refresh = OauthRefreshToken::query()
            ->with('client')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->groupBy('oauth_client_id');

        // When the client last actually did something. Kept on the access
        // tokens, because that is what a tool call is authenticated with.
        $lastUsed = OauthAccessToken::query()
            ->where('user_id', $user->id)
            ->whereNotNull('last_used_at')
            ->get(['oauth_client_id', 'last_used_at'])
            ->groupBy('oauth_client_id')
            ->map(fn ($tokens) => $tokens->max('last_used_at'));

        return $refresh->map(function ($tokens, $clientId) use ($lastUsed) {
            /** @var OauthClient $client */
            $client = $tokens->first()->client;

            return [
                'client_id' => $client->client_id,
                'name' => $client->name,
                'client_uri' => $client->client_uri,
                'scopes' => $tokens->flatMap(fn (OauthRefreshToken $t) => $t->scopes ?? [])
                    ->unique()->values()->all(),
                'connected_at' => $tokens->min('created_at')?->toIso8601String(),
                'expires_at' => $tokens->max('expires_at')?->toIso8601String(),
                'last_used_at' => $lastUsed->get($clientId)?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * Cuts a connection: every token of this user for this client. The client
     * registration itself stays — another user may still be using it.
     */
    public function revokeConnection(User $user, OauthClient $client): void
    {
        $this->revokeConnectionFor($user->id, $client->id);
    }

    /**
     * Throws out what has run out. Codes are gone for good after their five
     * minutes; tokens keep a week's grace so a connection list can still say
     * what happened.
     *
     * @return array{codes: int, access: int, refresh: int}
     */
    public function prune(): array
    {
        $cutoff = now()->subWeek();

        return [
            'codes' => OauthAuthorizationCode::query()->where('expires_at', '<', now())->delete(),
            'access' => OauthAccessToken::query()->where('expires_at', '<', $cutoff)->delete(),
            'refresh' => OauthRefreshToken::query()->where('expires_at', '<', $cutoff)->delete(),
        ];
    }

    /**
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    private function issueTokenPair(OauthClient $client, User $user, array $scopes): array
    {
        $ttl = (int) config('nextsearch.mcp.oauth.access_token_ttl_minutes');
        $refreshDays = (int) config('nextsearch.mcp.oauth.refresh_token_ttl_days');

        $accessPlain = OauthAccessToken::PREFIX.Str::random(self::RANDOM_LENGTH);
        $refreshPlain = OauthRefreshToken::PREFIX.Str::random(self::RANDOM_LENGTH);

        $access = new OauthAccessToken;
        $access->forceFill([
            'token_hash' => OauthAccessToken::hash($accessPlain),
            'oauth_client_id' => $client->id,
            'user_id' => $user->id,
            'scopes' => $scopes,
            'expires_at' => now()->addMinutes($ttl),
        ])->save();

        $refresh = new OauthRefreshToken;
        $refresh->forceFill([
            'token_hash' => OauthRefreshToken::hash($refreshPlain),
            'oauth_client_id' => $client->id,
            'user_id' => $user->id,
            'oauth_access_token_id' => $access->id,
            'scopes' => $scopes,
            'expires_at' => now()->addDays($refreshDays),
        ])->save();

        $client->forceFill(['last_used_at' => now()])->save();

        return [
            'access_token' => $accessPlain,
            'token_type' => 'Bearer',
            'expires_in' => $ttl * 60,
            'refresh_token' => $refreshPlain,
            'scope' => Scopes::toString($scopes),
        ];
    }

    /**
     * Everything that came out of one authorization code. Called when a code is
     * presented twice — the pair from the first exchange is then no longer
     * trustworthy.
     */
    private function revokeIssuedFor(OauthAuthorizationCode $code): void
    {
        $this->revokeConnectionFor($code->user_id, $code->oauth_client_id);
    }

    private function revokeConnectionFor(int $userId, int $clientId): void
    {
        OauthAccessToken::query()
            ->where('user_id', $userId)
            ->where('oauth_client_id', $clientId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        OauthRefreshToken::query()
            ->where('user_id', $userId)
            ->where('oauth_client_id', $clientId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
