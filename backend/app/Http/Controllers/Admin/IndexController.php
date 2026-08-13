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
        $index->flush();
        Document::query()->delete();

        // Without documents there is no stock either — the next crawl starts
        // from nothing, so the "Neu" automation has to wait for its baseline
        // again. See TagService::applyAutoTags().
        WatchedFolder::query()->update(['baseline_at' => null]);

        $this->settle();

        return response()->json(['message' => __('nextsearch.index.cleared')]);
    }

    public function rebuild(SearchIndex $index, IndexRunner $runner): JsonResponse
    {
        $index->flush();

        // Deliberately not deleting: the tags hang on the document rows, and a
        // rebuild is meant to renew the index, not throw away what people have
        // sorted by hand. The full crawl writes every row again via
        // updateOrCreate, purgeVanished() clears what is gone, and
        // `nextsearch:reconcile` picks up any orphan left in the index.
        Document::query()->update([
            'state' => Document::STATE_PENDING,
            'failure_reason' => null,
            'indexed_at' => null,
        ]);

        $this->settle();

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
     * Drops any queued crawl and process jobs and closes runs left hanging, so
     * a fresh crawl isn't refused.
     */
    private function settle(): void
    {
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
