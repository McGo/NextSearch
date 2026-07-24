<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Queue;

class QueueController extends Controller
{
    /** Only these queues may be cleared from the interface. */
    private const CLEARABLE = ['crawl', 'process'];

    /**
     * Empties a stuck queue. Meant for the case where a crawl was started more
     * than once and left a backlog of jobs re-processing documents that are
     * already indexed — clearing it stops the churn without touching the index.
     */
    public function clear(string $queue): JsonResponse
    {
        abort_unless(in_array($queue, self::CLEARABLE, true), 404);

        $removed = Queue::size($queue);
        Queue::connection()->clear($queue);

        return response()->json([
            'queue' => $queue,
            'removed' => $removed,
        ]);
    }
}
