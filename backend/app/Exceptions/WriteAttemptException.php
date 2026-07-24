<?php

namespace App\Exceptions;

use LogicException;

/**
 * Wird geworfen, bevor eine schreibende HTTP-Methode eine Nextcloud erreichen
 * kann. NextSearch liest Dateien, es verändert sie nicht — das ist die zentrale
 * Zusage des Produkts und wird hier im Code durchgesetzt, nicht nur im Handbuch.
 */
class WriteAttemptException extends LogicException
{
    public static function forMethod(string $method): self
    {
        return new self(sprintf(
            'NextSearch greift ausschließlich lesend auf Nextcloud zu. '
            .'Die Methode %s wurde blockiert.',
            strtoupper($method),
        ));
    }
}
