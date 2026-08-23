<?php

namespace App\Services\Mcp;

use App\Models\User;
use App\Services\OAuth\Scopes;

/**
 * Who is on the other end of an MCP request, and what they are allowed to do
 * with it.
 *
 * Two ways in lead here: a personal API key, which carries everything its owner
 * can do, and an OAuth token from the browser flow, which carries what the
 * owner ticked on the consent screen. Beyond that both are the same — the
 * permissions come from the user, never from the token.
 */
final readonly class McpIdentity
{
    /** Authenticated with a personal key from User settings › API keys. */
    public const SOURCE_KEY = 'api_key';

    /** Authenticated with a token from the OAuth flow. */
    public const SOURCE_OAUTH = 'oauth';

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public User $user,
        public array $scopes,
        public string $source,
        public ?string $clientName = null,
    ) {}

    public static function fromApiKey(User $user, string $keyName): self
    {
        return new self($user, Scopes::all(), self::SOURCE_KEY, $keyName);
    }

    public function can(string $scope): bool
    {
        return Scopes::allows($this->scopes, $scope);
    }
}
