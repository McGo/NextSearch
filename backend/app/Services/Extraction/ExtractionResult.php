<?php

namespace App\Services\Extraction;

final readonly class ExtractionResult
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $text,
        public array $metadata = [],
        public bool $ocrUsed = false,
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    public function characterCount(): int
    {
        return mb_strlen(trim($this->text));
    }
}
