<?php

namespace App\Services\Indexing;

use App\Jobs\CrawlFolderJob;
use App\Models\IndexRun;
use App\Models\WatchedFolder;

class IndexRunner
{
    /**
     * Starts a run for a folder. If one is already running it is returned
     * instead of placing a second one next to it.
     */
    public function start(WatchedFolder $folder, bool $full = false, string $trigger = 'schedule'): IndexRun
    {
        $running = $folder->runs()
            ->where('state', IndexRun::STATE_RUNNING)
            ->latest('id')
            ->first();

        if ($running !== null) {
            return $running;
        }

        $run = $folder->runs()->create([
            'state' => IndexRun::STATE_RUNNING,
            'trigger' => $trigger,
            'full' => $full,
            'started_at' => now(),
            // The root crawl is the first outstanding job.
            'pending_jobs' => 1,
        ]);

        CrawlFolderJob::dispatch($folder, $run, '', $full);

        return $run;
    }

    /**
     * Kick off all due folders. Called by the scheduler every minute.
     *
     * @return list<IndexRun>
     */
    public function startDue(): array
    {
        $runs = [];

        WatchedFolder::query()
            ->where('enabled', true)
            ->with('instance')
            ->each(function (WatchedFolder $folder) use (&$runs) {
                if (! $folder->instance->enabled || ! $folder->isDue()) {
                    return;
                }

                $runs[] = $this->start(
                    $folder,
                    trigger: $folder->crawl_requested_at !== null ? 'manual' : 'schedule',
                );
            });

        return $runs;
    }

    /**
     * Clean up stuck runs — e.g. after a restart in the middle of a crawl.
     */
    public function reapStale(int $afterHours = 6): int
    {
        return IndexRun::query()
            ->where('state', IndexRun::STATE_RUNNING)
            ->where('started_at', '<', now()->subHours($afterHours))
            ->update([
                'state' => IndexRun::STATE_FAILED,
                'finished_at' => now(),
            ]);
    }
}
