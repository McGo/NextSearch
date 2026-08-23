<?php

namespace App\Services\OAuth;

/**
 * Every URL the OAuth discovery documents name, in one place.
 *
 * They are all built from `APP_URL`, and that matters more here than anywhere
 * else in the application: a client compares the issuer it discovered against
 * the one in the metadata, and refuses the connection if they differ. An
 * installation behind a reverse proxy whose APP_URL still says
 * `http://localhost:3000` fails at exactly this point — see docs/mcp.md.
 */
final class Endpoints
{
    /** Path of the MCP endpoint, relative to the application root. */
    public const MCP_PATH = '/api/mcp';

    public static function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** The protected resource an access token is meant for. */
    public static function resource(): string
    {
        return self::issuer().self::MCP_PATH;
    }

    /**
     * RFC 9728 puts the metadata of a resource under its path, appended to the
     * well-known prefix. Clients that ignore the path and ask at the root are
     * served the same document.
     */
    public static function resourceMetadata(): string
    {
        return self::issuer().'/.well-known/oauth-protected-resource'.self::MCP_PATH;
    }

    /**
     * The consent screen. A page of the interface, not an API route — the user
     * arrives here in a browser and has to be able to sign in first.
     */
    public static function authorization(): string
    {
        return self::issuer().'/oauth/authorize';
    }

    public static function token(): string
    {
        return self::issuer().'/api/oauth/token';
    }

    public static function registration(): string
    {
        return self::issuer().'/api/oauth/register';
    }

    public static function revocation(): string
    {
        return self::issuer().'/api/oauth/revoke';
    }

    public static function documentation(): string
    {
        return 'https://github.com/McGo/NextSearch/blob/main/docs/mcp.md';
    }
}
