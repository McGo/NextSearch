<?php

namespace App\Services\Nextcloud;

use Carbon\CarbonImmutable;

/**
 * Ein Eintrag aus einer PROPFIND-Antwort — Datei oder Ordner.
 */
final readonly class RemoteEntry
{
    public function __construct(
        /** Path relative to the instance user's WebDAV root, without a leading slash. */
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
