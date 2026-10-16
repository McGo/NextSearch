<?php

namespace App\Jobs;

use App\Exceptions\NextcloudException;
use App\Models\Document;
use App\Models\IndexRun;
use App\Models\WatchedFolder;
use App\Services\Nextcloud\ReadOnlyWebDavClient;
use App\Services\Nextcloud\RemoteEntry;
use App\Services\Search\SearchIndex;
use App\Services\Tags\TagService;
use App\Support\DocumentDto;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Walks one directory level and re-dispatches itself for subfolders. Nextcloud
 * does not answer `Depth: infinity`, so it goes level by level.
 *
 * Every dispatched job is counted in the run; whoever brings the counter to
 * zero closes the run.
 */
class CrawlFolderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /**
     * @param  string  $subPath  Path relative to the watched folder. Empty = its root.
     */
    public function __construct(
        public WatchedFolder $folder,
        public IndexRun $run,
        public string $subPath = '',
        public bool $full = false,
    ) {
        $this->onQueue('crawl');
    }

    public function handle(ReadOnlyWebDavClient $dav, SearchIndex $index): void
    {
        $directory = trim($this->folder->normalizedPath().'/'.trim($this->subPath, '/'), '/');

        try {
            $entries = $dav->list($this->folder->instance, $directory);
        } catch (NextcloudException $e) {
            $this->run->recordError($directory, $e->getMessage());
            $this->run->settleJob();

            return;
        }

        $seenPaths = [];

        foreach ($entries as $entry) {
            if ($this->isExcluded($entry)) {
                continue;
            }

            if ($entry->isDirectory) {
                $this->run->trackJobs();
                self::dispatch($this->folder, $this->run, $this->relativeTo($entry->path), $this->full);

                continue;
            }

            $seenPaths[] = $entry->path;
            $this->handleFile($entry, $index);
        }

        $this->purgeVanished($directory, $seenPaths, $index);
        $this->run->settleJob();
    }

    private function handleFile(RemoteEntry $entry, SearchIndex $index): void
    {
        $this->run->bump('files_seen');

        $atPath = Document::query()
            ->where('watched_folder_id', $this->folder->id)
            ->where('path_hash', Document::hashPath($entry->path))
            ->first();

        // A document is the Nextcloud file, not the path. Is this file known
        // under another path — renamed, moved, or moved over from another
        // watched folder? Then that document comes here, uuid and tags
        // included, instead of a new one being created.
        $moved = $this->findMoved($entry, $atPath);

        if ($moved !== null) {
            if ($atPath !== null) {
                // Whatever was registered at this path is a different file
                // that is no longer there. It makes room.
                $index->forget([$atPath->uuid]);
                $atPath->markVanished();
            }

            $this->relocate($moved, $entry, $index);

            return;
        }

        $document = $atPath;

        if (! $this->full && $document?->matchesRemote($entry->etag, $entry->size)) {
            $this->run->bump('files_skipped');

            return;
        }

        if (! $this->isIndexable($entry)) {
            $this->storeSkipped($document, $entry);

            return;
        }

        $isNew = $document === null;

        $document = Document::query()->updateOrCreate(
            [
                'watched_folder_id' => $this->folder->id,
                'path_hash' => Document::hashPath($entry->path),
            ],
            [
                'nextcloud_instance_id' => $this->folder->nextcloud_instance_id,
                'oc_file_id' => $entry->fileId,
                ...$this->locationAttributes($entry),
                'size' => $entry->size,
                'remote_modified_at' => $entry->modifiedAt,
                'etag' => $entry->etag,
                'state' => Document::STATE_PENDING,
                'failure_reason' => null,
            ],
        );

        $this->run->bump($isNew ? 'files_new' : 'files_updated');
        $this->run->trackJobs();

        ProcessDocumentJob::dispatch($document, $this->run);
    }

    /**
     * The document this file used to be, if it lived under another path.
     *
     * A row already at this path with the same file id is no move. Neither is
     * a row in another folder with the same path: two watched folders that
     * overlap both see the file, and each keeps its own document for it. Among
     * the rest, a document of this folder wins over one from elsewhere.
     */
    private function findMoved(RemoteEntry $entry, ?Document $atPath): ?Document
    {
        if ($entry->fileId === null || $entry->fileId === '' || $atPath?->oc_file_id === $entry->fileId) {
            return null;
        }

        return Document::query()
            ->withVanished()
            ->where('nextcloud_instance_id', $this->folder->nextcloud_instance_id)
            ->where('oc_file_id', $entry->fileId)
            ->where(fn ($query) => $query
                ->whereNotNull('vanished_at')
                ->orWhere('remote_path', '!=', $entry->path))
            ->orderByRaw('CASE WHEN watched_folder_id = ? THEN 0 ELSE 1 END', [$this->folder->id])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Moves a known document to where the file lies now. Text, preview and
     * tags stay; only what follows from the location changes. The file is
     * only fetched again if its content changed along the way.
     */
    private function relocate(Document $document, RemoteEntry $entry, SearchIndex $index): void
    {
        $wasVanished = $document->isVanished();
        $contentChanged = ! $document->matchesRemote($entry->etag, $entry->size);

        $document->forceFill([
            'watched_folder_id' => $this->folder->id,
            'path_hash' => Document::hashPath($entry->path),
            'vanished_at' => null,
            ...$this->locationAttributes($entry),
        ])->save();

        $this->run->bump('files_moved');

        if ($contentChanged || ($this->full && $this->isIndexable($entry))) {
            $this->reprocess($document, $entry);

            return;
        }

        if (! $wasVanished) {
            // Still in the index — only the fields that follow from the
            // location need to change there.
            $index->patch([$this->locationPatch($document)]);

            return;
        }

        // Back from vanished: the index entry is gone. The stored text puts it
        // back without fetching and extracting the file again.
        $text = $this->storedText($document);

        if ($text === null) {
            $this->reprocess($document, $entry);

            return;
        }

        $index->upsert(DocumentDto::fromModel($document, $text, app(TagService::class)->tokensFor($document)));
    }

    private function reprocess(Document $document, RemoteEntry $entry): void
    {
        if (! $this->isIndexable($entry)) {
            $document->forceFill(['state' => Document::STATE_SKIPPED, 'etag' => $entry->etag, 'size' => $entry->size])->save();

            return;
        }

        $document->forceFill([
            'size' => $entry->size,
            'remote_modified_at' => $entry->modifiedAt,
            'etag' => $entry->etag,
            'state' => Document::STATE_PENDING,
            'failure_reason' => null,
        ])->save();

        $this->run->trackJobs();
        ProcessDocumentJob::dispatch($document, $this->run);
    }

    /**
     * The full text from object storage, or null if there is none to be had.
     * An empty document has no stored text, and that is fine — it indexes
     * with an empty string.
     */
    private function storedText(Document $document): ?string
    {
        if ($document->state !== Document::STATE_INDEXED) {
            return null;
        }

        if ($document->text_key === null) {
            return '';
        }

        try {
            return Storage::disk((string) config('nextsearch.preview.disk'))->get($document->text_key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function locationAttributes(RemoteEntry $entry): array
    {
        return [
            'remote_path' => $entry->path,
            'name' => $entry->name,
            'extension' => $entry->extension(),
            'mime_type' => $entry->contentType,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function locationPatch(Document $document): array
    {
        return [
            'id' => str_replace('-', '', $document->uuid),
            'folder_id' => $this->folder->id,
            'folder_label' => $this->folder->label,
            'name' => $document->name,
            'path' => $document->remote_path,
            'directory' => trim(dirname($document->remote_path), '.'),
            'path_segments' => DocumentDto::pathSegments($document->remote_path),
            'extension' => $document->extension ?? 'none',
            'mime_type' => $document->mime_type,
        ];
    }

    /**
     * Files that no longer appeared in this run have been removed, renamed or
     * moved. They leave the search index right away, but the rows are kept as
     * vanished: if the file turns up elsewhere — a later directory of this
     * run, another folder, the next run — it takes its uuid and tags along.
     * What stays vanished is deleted by `nextsearch:purge-vanished` once the
     * retention period is up. Only the direct level is considered —
     * subfolders are cleaned up by their own job.
     *
     * @param  list<string>  $seenPaths
     */
    private function purgeVanished(string $directory, array $seenPaths, SearchIndex $index): void
    {
        $prefix = $this->escapeLike($directory).'/';
        $hashes = array_map(Document::hashPath(...), $seenPaths);

        $stale = Document::query()
            ->where('watched_folder_id', $this->folder->id)
            ->where('remote_path', 'like', $prefix.'%')
            ->where('remote_path', 'not like', $prefix.'%/%')
            ->when($hashes !== [], fn ($query) => $query->whereNotIn('path_hash', $hashes))
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        $index->forget($stale->pluck('uuid')->all());
        $stale->each(fn (Document $document) => $document->markVanished());

        $this->run->bump('files_removed', $stale->count());
    }

    private function isIndexable(RemoteEntry $entry): bool
    {
        $extension = $entry->extension();

        if ($extension === null || ! in_array($extension, config('nextsearch.index.extensions'), true)) {
            return false;
        }

        return $entry->size > 0
            && $entry->size <= (int) config('nextsearch.index.max_file_size');
    }

    private function storeSkipped(?Document $existing, RemoteEntry $entry): void
    {
        $this->run->bump('files_skipped');

        if ($existing !== null) {
            $existing->forceFill([
                'state' => Document::STATE_SKIPPED,
                'etag' => $entry->etag,
                'size' => $entry->size,
            ])->save();
        }
    }

    private function isExcluded(RemoteEntry $entry): bool
    {
        $ignored = config('nextsearch.index.ignored_directories', []);

        if ($entry->isDirectory && in_array($entry->name, $ignored, true)) {
            return true;
        }

        foreach ($this->folder->exclude_patterns ?? [] as $pattern) {
            if (fnmatch($pattern, $entry->path) || fnmatch($pattern, $entry->name)) {
                return true;
            }
        }

        return false;
    }

    private function relativeTo(string $absolutePath): string
    {
        return trim(substr($absolutePath, strlen($this->folder->normalizedPath())), '/');
    }

    /**
     * Folder names may contain % and _; LIKE must not read them as wildcards.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Crawl failed', [
            'folder' => $this->folder->uuid,
            'sub_path' => $this->subPath,
            'message' => $e->getMessage(),
        ]);

        $this->run->recordError($this->subPath, $e->getMessage());
        $this->run->settleJob();
    }
}
