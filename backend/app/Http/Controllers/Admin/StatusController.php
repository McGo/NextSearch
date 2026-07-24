<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\IndexRun;
use App\Services\Extraction\TikaClient;
use App\Services\Search\SearchIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Queue;

class StatusController extends Controller
{
    public function __invoke(SearchIndex $index, TikaClient $tika): JsonResponse
    {
        $runs = IndexRun::query()
            ->with('folder.instance')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (IndexRun $run) => [
                'uuid' => $run->uuid,
                'state' => $run->state,
                'trigger' => $run->trigger,
                'full' => $run->full,
                'folder' => $run->folder->label,
                'instance' => $run->folder->instance->name,
                'files_seen' => $run->files_seen,
                'files_new' => $run->files_new,
                'files_updated' => $run->files_updated,
                'files_removed' => $run->files_removed,
                'files_skipped' => $run->files_skipped,
                'files_failed' => $run->files_failed,
                'pending_jobs' => $run->pending_jobs,
                'errors' => $run->errors ?? [],
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ]);

        return response()->json([
            'runs' => $runs,
            'documents' => Document::query()
                ->selectRaw('state, count(*) as total')
                ->groupBy('state')
                ->pluck('total', 'state'),
            'queues' => [
                'crawl' => Queue::size('crawl'),
                'process' => Queue::size('process'),
            ],
            'services' => [
                'tika' => $tika->isReachable(),
                'search' => $index->stats(),
            ],
        ]);
    }
}
