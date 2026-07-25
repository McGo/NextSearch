<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\IndexRun;
use App\Models\WatchedFolder;
use App\Services\Indexing\IndexRunner;
use App\Services\Search\SearchIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Queue;

/**
 * Whole-index maintenance: empty it, or rebuild it from scratch. The way out
 * when documents are left orphaned in the index — e.g. after a deletion whose
 * index cleanup didn't complete.
 */
class IndexController extends Controller
{
    public function clear(SearchIndex $index): JsonResponse
    {
        $this->reset($index);

        return response()->json(['message' => __('nextsearch.index.cleared')]);
    }

    public function rebuild(SearchIndex $index, IndexRunner $runner): JsonResponse
    {
        $this->reset($index);

        $folders = WatchedFolder::query()->where('enabled', true)->get();

        foreach ($folders as $folder) {
            $runner->start($folder, full: true, trigger: 'manual');
        }

        return response()->json([
            'folders' => $folders->count(),
            'message' => __('nextsearch.index.rebuilding', ['count' => $folders->count()]),
        ]);
    }

    /**
     * Wipes the search index and the document state, drops any queued crawl and
     * process jobs, and closes runs left hanging so a fresh crawl isn't refused.
     */
    private function reset(SearchIndex $index): void
    {
        $index->flush();
        Document::query()->delete();

        // Drop stale jobs so nothing runs against the wiped state. Driver-safe:
        // only RedisQueue has clear(); other drivers (and the test fake) don't.
        try {
            $connection = Queue::connection();
            if (method_exists($connection, 'clear')) {
                $connection->clear('crawl');
                $connection->clear('process');
            }
        } catch (\Throwable) {
            // No clearable queue — nothing to drop.
        }

        IndexRun::query()
            ->where('state', IndexRun::STATE_RUNNING)
            ->update([
                'state' => IndexRun::STATE_COMPLETED,
                'pending_jobs' => 0,
                'finished_at' => now(),
            ]);
    }
}
