<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An MCP client that has registered itself — Claude Desktop, an editor, a
 * script with a browser at hand. It holds no permissions of its own; what a
 * token issued to it may do follows from the user who approved it.
 */
class OauthClient extends Model
{
    use HasFactory;

    /** Public clients hold no secret and prove themselves with PKCE alone. */
    public const AUTH_NONE = 'none';

    public const AUTH_SECRET_POST = 'client_secret_post';

    public const AUTH_SECRET_BASIC = 'client_secret_basic';

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'is_dynamic' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OauthAccessToken, $this>
     */
    public function accessTokens(): HasMany
    {
        return $this->hasMany(OauthAccessToken::class);
    }

    public function isPublic(): bool
    {
        return $this->token_endpoint_auth_method === self::AUTH_NONE;
    }

    /**
     * Redirect URIs are compared in full, never by prefix. A registered
     * `https://claude.ai/api/mcp/auth_callback` must not let
     * `https://claude.ai/api/mcp/auth_callback.evil.example` through.
     *
     * One exception, and RFC 8252 §7.3 asks for it: a client listening on the
     * loopback interface picks a free port at startup and rarely gets the same
     * one twice. For those the port is ignored and the rest has to match.
     */
    public function allowsRedirect(string $uri): bool
    {
        $registered = $this->redirect_uris ?? [];

        if (in_array($uri, $registered, true)) {
            return true;
        }

        $wanted = self::loopbackKey($uri);

        if ($wanted === null) {
            return false;
        }

        foreach ($registered as $candidate) {
            if (self::loopbackKey($candidate) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything but the port of a loopback callback, or null if this is not
     * one. Query strings are part of it — a registered callback that carries
     * one must not be matched by one that does not.
     */
    private static function loopbackKey(string $uri): ?string
    {
        $parts = parse_url($uri);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'http') {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');

        if (! in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)) {
            return null;
        }

        return $host.($parts['path'] ?? '').'?'.($parts['query'] ?? '');
    }

    public function secretMatches(?string $secret): bool
    {
        if ($this->secret_hash === null) {
            return $secret === null || $secret === '';
        }

        return is_string($secret) && hash_equals($this->secret_hash, hash('sha256', $secret));
    }

    public static function newClientId(): string
    {
        return 'nxc_'.Str::random(32);
    }
}
