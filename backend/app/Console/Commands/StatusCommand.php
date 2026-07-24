<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\IndexRun;
use App\Models\NextcloudInstance;
use App\Services\Extraction\TikaClient;
use App\Services\Search\SearchIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class StatusCommand extends Command
{
    protected $signature = 'nextsearch:status';

    protected $description = 'Show the state of instances, index and queue';

    public function handle(SearchIndex $index, TikaClient $tika): int
    {
        $this->components->twoColumnDetail('<fg=gray>Service</>', '<fg=gray>State</>');
        $this->components->twoColumnDetail('Tika', $tika->isReachable() ? '<fg=green>reachable</>' : '<fg=red>unreachable</>');
        $this->components->twoColumnDetail('Search index', number_format((float) ($index->stats()['numberOfDocuments'] ?? 0), 0, ',', '.').' documents');

        foreach (['crawl', 'process', 'preview'] as $queue) {
            $this->components->twoColumnDetail('Queue '.$queue, (string) Queue::size($queue));
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Instance</>', '<fg=gray>Connection</>');

        foreach (NextcloudInstance::query()->withCount('folders')->get() as $instance) {
            $this->components->twoColumnDetail(
                sprintf('%s (%d folders)', $instance->name, $instance->folders_count),
                match ($instance->health_state) {
                    NextcloudInstance::HEALTH_OK => '<fg=green>ok</>',
                    NextcloudInstance::HEALTH_FAILED => '<fg=red>error</>',
                    default => '<fg=yellow>unchecked</>',
                },
            );
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Documents</>', '<fg=gray>Count</>');

        foreach (Document::query()->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state') as $state => $total) {
            $this->components->twoColumnDetail($state, (string) $total);
        }

        $runs = IndexRun::query()->with('folder')->latest('id')->limit(5)->get();

        if ($runs->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['Folder', 'State', 'seen', 'new', 'changed', 'removed', 'errors', 'open'],
                $runs->map(fn (IndexRun $run) => [
                    $run->folder->label,
                    $run->state,
                    $run->files_seen,
                    $run->files_new,
                    $run->files_updated,
                    $run->files_removed,
                    $run->files_failed,
                    $run->pending_jobs,
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
