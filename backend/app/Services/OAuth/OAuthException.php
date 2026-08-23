<?php

namespace App\Services\OAuth;

use RuntimeException;

/**
 * An error in the shape RFC 6749 §5.2 prescribes. The token endpoint renders it
 * as `{"error": "...", "error_description": "..."}` — a client parses that,
 * a Laravel validation envelope it would not.
 */
class OAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $description = '',
        public readonly int $status = 400,
    ) {
        parent::__construct($description === '' ? $error : $description);
    }

    public static function invalidRequest(string $description): self
    {
        return new self('invalid_request', $description);
    }

    public static function invalidClient(string $description): self
    {
        return new self('invalid_client', $description, 401);
    }

    public static function invalidGrant(string $description): self
    {
        return new self('invalid_grant', $description);
    }

    public static function unsupportedGrantType(string $type): self
    {
        return new self('unsupported_grant_type', "Grant type \"{$type}\" is not supported.");
    }

    /**
     * @return array<string, string>
     */
    public function payload(): array
    {
        return array_filter([
            'error' => $this->error,
            'error_description' => $this->description,
        ]);
    }
}
