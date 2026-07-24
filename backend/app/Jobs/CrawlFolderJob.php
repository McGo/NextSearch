<?php

namespace App\Jobs;

use App\Exceptions\NextcloudException;
use App\Models\Document;
use App\Models\IndexRun;
use App\Models\WatchedFolder;
use App\Services\Nextcloud\ReadOnlyWebDavClient;
use App\Services\Nextcloud\RemoteEntry;
use App\Services\Search\SearchIndex;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Läuft eine Verzeichnisebene ab und stößt für Unterordner erneut sich selbst
 * an. Nextcloud beantwortet `Depth: infinity` nicht, also Ebene für Ebene.
 *
 * Jeder dispatchte Job wird im Lauf mitgezählt; wer den Zähler auf null bringt,
 * schließt den Lauf ab.
 */
class CrawlFolderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /**
     * @param  string  $subPath  Pfad relativ zum überwachten Ordner. Leer = dessen Wurzel.
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
            $this->handleFile($entry);
        }

        $this->purgeVanished($directory, $seenPaths, $index);
        $this->run->settleJob();
    }

    private function handleFile(RemoteEntry $entry): void
    {
        $this->run->bump('files_seen');

        $document = Document::query()
            ->where('watched_folder_id', $this->folder->id)
            ->where('path_hash', Document::hashPath($entry->path))
            ->first();

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
                'remote_path' => $entry->path,
                'name' => $entry->name,
                'extension' => $entry->extension(),
                'mime_type' => $entry->contentType,
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
     * Dateien, die in diesem Durchlauf nicht mehr auftauchten, sind entfernt
     * oder umbenannt worden und fliegen aus Datenbank und Index. Betrachtet wird
     * nur die direkte Ebene — Unterordner räumt ihr eigener Job auf.
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
        Document::query()->whereIn('id', $stale->pluck('id'))->delete();

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
     * Ordnernamen dürfen % und _ enthalten, LIKE darf sie nicht als Platzhalter
     * verstehen.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Crawl fehlgeschlagen', [
            'folder' => $this->folder->uuid,
            'sub_path' => $this->subPath,
            'message' => $e->getMessage(),
        ]);

        $this->run->recordError($this->subPath, $e->getMessage());
        $this->run->settleJob();
    }
}
