<?php

namespace App\Services\OAuth;

/**
 * What an MCP client may be granted. Deliberately two, not twelve: a scope
 * nobody understands on a consent screen protects nothing.
 *
 * A personal API key holds all of them — it already carries its owner's full
 * permissions, and narrowing it afterwards would be a promise the interface
 * does not make either.
 */
final class Scopes
{
    /** Searching and reading document metadata. */
    public const DOCUMENTS_READ = 'documents:read';

    /** Hanging tags on documents and taking them off. */
    public const TAGS_WRITE = 'tags:write';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::DOCUMENTS_READ, self::TAGS_WRITE];
    }

    /**
     * What a client gets when it asks for nothing in particular. Reading only —
     * whoever wants to write says so.
     *
     * @return list<string>
     */
    public static function fallback(): array
    {
        return [self::DOCUMENTS_READ];
    }

    /**
     * Turns a scope string into the list we recognise. Unknown entries are
     * dropped rather than refused: RFC 6749 lets a server issue less than was
     * asked for, and the client is told what it got.
     *
     * @return list<string>
     */
    public static function parse(?string $requested): array
    {
        if ($requested === null || trim($requested) === '') {
            return self::fallback();
        }

        $known = array_values(array_intersect(
            self::all(),
            preg_split('/\s+/', trim($requested)) ?: [],
        ));

        return $known === [] ? self::fallback() : $known;
    }

    /**
     * @param  list<string>  $scopes
     */
    public static function toString(array $scopes): string
    {
        return implode(' ', $scopes);
    }

    /**
     * @param  list<string>  $granted
     */
    public static function allows(array $granted, string $scope): bool
    {
        return in_array($scope, $granted, true);
    }
}
