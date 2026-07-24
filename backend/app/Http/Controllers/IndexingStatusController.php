<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\IndexRun;
use Illuminate\Http\JsonResponse;

/**
 * A light heartbeat for the search page: is indexing running, and roughly how
 * much is still outstanding. Open to every signed-in user — the numbers are not
 * sensitive, and it lets the search show that results are still filling in.
 */
class IndexingStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $running = IndexRun::query()
            ->where('state', IndexRun::STATE_RUNNING)
            ->get(['id', 'pending_jobs', 'files_new', 'files_updated', 'files_seen']);

        return response()->json([
            'running' => $running->isNotEmpty(),
            // Outstanding crawl/process/preview jobs across all running crawls.
            'pending' => (int) $running->sum('pending_jobs'),
            'indexed' => Document::query()->where('state', Document::STATE_INDEXED)->count(),
        ]);
    }
}
