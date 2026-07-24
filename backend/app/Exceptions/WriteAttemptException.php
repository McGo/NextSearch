<?php

namespace App\Exceptions;

use LogicException;

/**
 * Thrown before a writing HTTP method can reach a Nextcloud. NextSearch reads
 * files, it does not change them — that is the product's core promise, enforced
 * here in the code, not just in the manual.
 */
class WriteAttemptException extends LogicException
{
    public static function forMethod(string $method): self
    {
        return new self(__('nextsearch.nextcloud.write_blocked', ['method' => strtoupper($method)]));
    }
}
