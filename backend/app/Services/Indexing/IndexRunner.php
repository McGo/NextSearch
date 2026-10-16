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
     *
     * With a sub path only that directory and what lies below it is crawled —
     * the quick way to make a file that was just dropped there findable. If a
     * run is already going, the directory is added to it: the running crawl
     * may have passed that directory before the file arrived.
     *
     * @param  string  $subPath  Relative to the watched folder. Empty = the whole folder.
     */
    public function start(WatchedFolder $folder, bool $full = false, string $trigger = 'schedule', string $subPath = ''): IndexRun
    {
        $subPath = trim($subPath, '/');

        $running = $folder->runs()
            ->where('state', IndexRun::STATE_RUNNING)
            ->latest('id')
            ->first();

        if ($running !== null) {
            if ($subPath !== '') {
                $running->trackJobs();
                CrawlFolderJob::dispatch($folder, $running, $subPath, $full);
            }

            return $running;
        }

        $run = $folder->runs()->create([
            'state' => IndexRun::STATE_RUNNING,
            'trigger' => $trigger,
            'full' => $full,
            'sub_path' => $subPath === '' ? null : $subPath,
            'started_at' => now(),
            // The root crawl is the first outstanding job.
            'pending_jobs' => 1,
        ]);

        CrawlFolderJob::dispatch($folder, $run, $subPath, $full);

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
