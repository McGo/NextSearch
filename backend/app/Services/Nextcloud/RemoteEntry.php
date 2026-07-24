<?php

namespace App\Services\Nextcloud;

use Carbon\CarbonImmutable;

/**
 * Ein Eintrag aus einer PROPFIND-Antwort — Datei oder Ordner.
 */
final readonly class RemoteEntry
{
    public function __construct(
        /** Pfad relativ zur WebDAV-Wurzel des Instanz-Benutzers, ohne führenden Slash. */
        public string $path,
        public string $name,
        public bool $isDirectory,
        public ?string $fileId,
        public ?string $etag,
        public int $size,
        public ?string $contentType,
        public ?CarbonImmutable $modifiedAt,
    ) {}

    public function extension(): ?string
    {
        $extension = pathinfo($this->name, PATHINFO_EXTENSION);

        return $extension === '' ? null : mb_strtolower($extension);
    }
}
