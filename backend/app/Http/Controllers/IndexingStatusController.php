<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Queue;

/**
 * A light heartbeat for the search page: is indexing running, and roughly how
 * much is still outstanding. Open to every signed-in user — the numbers are not
 * sensitive, and it lets the search show that results are still filling in.
 */
class IndexingStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Based on real queue depth (Queue::size counts queued, delayed and
        // in-flight jobs), not on a run's pending_jobs counter — a run that was
        // interrupted can leave that counter stuck above zero, which would keep
        // the banner up forever even though nothing is being processed.
        $queued = Queue::size('crawl') + Queue::size('process');

        return response()->json([
            'running' => $queued > 0,
            // Documents not yet in the search index — the real "still to go".
            'pending' => Document::query()->where('state', Document::STATE_PENDING)->count(),
            'indexed' => Document::query()->where('state', Document::STATE_INDEXED)->count(),
        ]);
    }
}
