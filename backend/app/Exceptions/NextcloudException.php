<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class NextcloudException extends RuntimeException
{
    public static function unauthorized(): self
    {
        return new self(__('nextsearch.nextcloud.unauthorized'));
    }

    public static function notFound(string $path): self
    {
        return new self(__('nextsearch.nextcloud.not_found', ['path' => $path]));
    }

    public static function unreachable(string $url, Throwable $previous): self
    {
        return new self(
            __('nextsearch.nextcloud.unreachable', ['url' => $url, 'message' => $previous->getMessage()]),
            previous: $previous,
        );
    }

    public static function unexpectedStatus(int $status, string $path): self
    {
        return new self(__('nextsearch.nextcloud.unexpected_status', ['status' => $status, 'path' => $path]));
    }
}
