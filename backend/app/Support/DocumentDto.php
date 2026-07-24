<?php

namespace App\Support;

use App\Models\Document;
use Carbon\CarbonInterface;

/**
 * The only interface between extraction and the search index. Whatever isn't
 * here doesn't land in the index either.
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
     * Prepared for Meilisearch. The derived fields (year, size bucket) exist
     * only so they work as facets.
     *
     * @return array<string, mixed>
     */
    public function toSearchDocument(): array
    {
        $limit = (int) config('nextsearch.index.max_indexed_characters');

        return [
            'id' => str_replace('-', '', $this->uuid),
            'uuid' => $this->uuid,

            // Origin — also carries the access check: every search is filtered
            // server-side to the shared folder_ids.
            'instance_id' => $this->instanceId,
            'instance_name' => $this->instanceName,
            'folder_id' => $this->folderId,
            'folder_label' => $this->folderLabel,

            'name' => $this->name,
            'path' => $this->path,
            'directory' => trim(dirname($this->path), '.'),
            // Every folder in the path as its own value — so a document can be
            // found and filtered by any folder it sits under, not just its
            // watched-folder label.
            'path_segments' => self::pathSegments($this->path),
            // Language-neutral marker; the frontend shows a translated label.
            'extension' => $this->extension ?? 'none',
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

    /**
     * The folder components of a path, without the file name.
     * "Akten/2019/rechnung.pdf" → ["Akten", "2019"].
     *
     * @return list<string>
     */
    public static function pathSegments(string $path): array
    {
        $directory = trim(dirname($path), '.');

        if ($directory === '' || $directory === '/') {
            return [];
        }

        return array_values(array_filter(explode('/', trim($directory, '/')), fn ($s) => $s !== ''));
    }

    /**
     * A language-neutral key, not a human label — the frontend translates it.
     * These keys are indexed as facet values, so changing them needs a reindex.
     */
    public static function sizeBucket(int $bytes): string
    {
        return match (true) {
            $bytes < 100 * 1024 => 'upTo100kb',
            $bytes < 1024 * 1024 => '100kbTo1mb',
            $bytes < 10 * 1024 * 1024 => '1to10mb',
            $bytes < 100 * 1024 * 1024 => '10to100mb',
            default => 'over100mb',
        };
    }
}
