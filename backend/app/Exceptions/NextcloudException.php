<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class NextcloudException extends RuntimeException
{
    public static function unauthorized(): self
    {
        return new self('Anmeldung abgelehnt. Benutzername oder App-Passwort stimmen nicht.');
    }

    public static function notFound(string $path): self
    {
        return new self(sprintf('Pfad "%s" existiert auf der Instanz nicht.', $path));
    }

    public static function unreachable(string $url, Throwable $previous): self
    {
        return new self(
            sprintf('Instanz unter %s nicht erreichbar: %s', $url, $previous->getMessage()),
            previous: $previous,
        );
    }

    public static function unexpectedStatus(int $status, string $path): self
    {
        return new self(sprintf('Unerwartete Antwort %d für "%s".', $status, $path));
    }
}
