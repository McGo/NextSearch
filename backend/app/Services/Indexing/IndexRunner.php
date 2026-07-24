<?php

namespace App\Services\Indexing;

use App\Jobs\CrawlFolderJob;
use App\Models\IndexRun;
use App\Models\WatchedFolder;

class IndexRunner
{
    /**
     * Startet einen Durchlauf für einen Ordner. Läuft bereits einer, wird er
     * zurückgegeben statt einen zweiten daneben zu setzen.
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
            // Der Wurzel-Crawl ist der erste offene Job.
            'pending_jobs' => 1,
        ]);

        CrawlFolderJob::dispatch($folder, $run, '', $full);

        return $run;
    }

    /**
     * Alle fälligen Ordner anstoßen. Ruft der Scheduler minütlich auf.
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
     * Hängengebliebene Läufe aufräumen — etwa nach einem Neustart mitten im
     * Durchlauf.
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
