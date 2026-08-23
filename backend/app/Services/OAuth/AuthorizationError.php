<?php

namespace App\Services\OAuth;

use RuntimeException;

/**
 * Something wrong with an authorization request. Two kinds, and the difference
 * matters.
 *
 * A request naming an unknown client or a callback that client never
 * registered is not sent anywhere — that is precisely the case where a redirect
 * would hand a code to a stranger. It stops on the consent page with a message.
 *
 * Everything after those two checks is the client's problem and is reported
 * where the client is listening: back at its own callback, as `error=…`.
 */
class AuthorizationError extends RuntimeException
{
    private function __construct(
        public readonly ?string $redirectTo,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Shown on the consent page. Nothing is redirected. */
    public static function fatal(string $message): self
    {
        return new self(null, $message);
    }

    /** Reported back to the client at its callback. */
    public static function redirect(string $redirectUri, string $error, string $description, ?string $state): self
    {
        $query = array_filter([
            'error' => $error,
            'error_description' => $description,
            'state' => $state,
        ], fn ($value) => $value !== null && $value !== '');

        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return new self($redirectUri.$separator.http_build_query($query), $description);
    }
}
