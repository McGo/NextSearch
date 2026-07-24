<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Search\SearchIndex;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes a renamed folder's new label onto all its documents in the search
 * index. No Nextcloud access, no extraction — just the label, in chunks, so a
 * rename stays cheap even for a large folder.
 */
class RelabelFolderDocumentsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $folderId,
        public string $label,
    ) {
        // Light work — kept off the crawl/process queues so it doesn't wait
        // behind extraction.
        $this->onQueue('default');
    }

    public function handle(SearchIndex $index): void
    {
        Document::query()
            ->where('watched_folder_id', $this->folderId)
            ->orderBy('id')
            ->chunkById(1000, function ($documents) use ($index) {
                $index->relabelDocuments($documents->pluck('uuid')->all(), $this->label);
            });
    }
}
