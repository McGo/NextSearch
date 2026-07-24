<?php

namespace App\Support;

use App\Models\Document;
use Carbon\CarbonInterface;

/**
 * Die einzige Schnittstelle zwischen Extraktion und Suchindex. Was hier nicht
 * drinsteht, landet auch nicht im Index.
 */
final readonly class DocumentDto
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $uuid,
        public int $instanceId,
        public string $instanceName,
        public int $folderId,
        public string $folderLabel,
        public string $path,
        public string $name,
        public ?string $extension,
        public ?string $mimeType,
        public int $size,
        public ?CarbonInterface $modifiedAt,
        public string $text,
        public array $metadata = [],
        public bool $ocrUsed = false,
        public ?string $previewKey = null,
    ) {}

    public static function fromModel(Document $document, string $text): self
    {
        $document->loadMissing(['folder', 'instance']);
        $metadata = $document->metadata ?? [];

        return new self(
            uuid: $document->uuid,
            instanceId: $document->nextcloud_instance_id,
            instanceName: $document->instance->name,
            folderId: $document->watched_folder_id,
            folderLabel: $document->folder->label,
            path: $document->remote_path,
            name: $document->name,
            extension: $document->extension,
            mimeType: $document->mime_type,
            size: $document->size,
            modifiedAt: $document->remote_modified_at,
            text: $text,
            metadata: $metadata,
            ocrUsed: $document->ocr_used,
            previewKey: $document->preview_key,
        );
    }

    /**
     * Aufbereitung für Meilisearch. Die abgeleiteten Felder (Jahr, Größenklasse)
     * existieren nur, damit sie als Facetten taugen.
     *
     * @return array<string, mixed>
     */
    public function toSearchDocument(): array
    {
        $limit = (int) config('nextsearch.index.max_indexed_characters');

        return [
            'id' => str_replace('-', '', $this->uuid),
            'uuid' => $this->uuid,

            // Herkunft — trägt zugleich die Zugriffsprüfung: jede Suche wird
            // serverseitig auf die freigegebenen folder_id gefiltert.
            'instance_id' => $this->instanceId,
            'instance_name' => $this->instanceName,
            'folder_id' => $this->folderId,
            'folder_label' => $this->folderLabel,

            'name' => $this->name,
            'path' => $this->path,
            'directory' => trim(dirname($this->path), '.'),
            'extension' => $this->extension ?? 'ohne',
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'size_bucket' => self::sizeBucket($this->size),

            'modified_at' => $this->modifiedAt?->getTimestamp(),
            'year' => $this->modifiedAt?->year,
            'month' => $this->modifiedAt?->format('Y-m'),

            'title' => $this->metadata['title'] ?? null,
            'author' => $this->metadata['author'] ?? null,
            'language' => $this->metadata['language'] ?? null,
            'page_count' => $this->metadata['page_count'] ?? null,
            'ocr_used' => $this->ocrUsed,
            'has_preview' => $this->previewKey !== null,

            'content' => mb_substr($this->text, 0, $limit),
        ];
    }

    public static function sizeBucket(int $bytes): string
    {
        return match (true) {
            $bytes < 100 * 1024 => 'bis 100 KB',
            $bytes < 1024 * 1024 => '100 KB bis 1 MB',
            $bytes < 10 * 1024 * 1024 => '1 bis 10 MB',
            $bytes < 100 * 1024 * 1024 => '10 bis 100 MB',
            default => 'über 100 MB',
        };
    }
}
