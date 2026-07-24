<?php

namespace App\Console\Commands;

use App\Models\NextcloudInstance;
use App\Models\WatchedFolder;
use App\Services\Indexing\IndexRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class IndexCommand extends Command
{
    protected $signature = 'nextsearch:index
        {--instance= : UUID or name of an instance}
        {--folder= : UUID of a watched folder}
        {--full : Skip delta detection and reprocess everything}
        {--due-only : Only folders whose interval has elapsed}';

    protected $description = 'Crawl and index the watched folders';

    public function handle(IndexRunner $runner): int
    {
        if ($this->option('due-only')) {
            $runs = $runner->startDue();
            $this->info(sprintf('Kicked off %d run(s).', count($runs)));

            return self::SUCCESS;
        }

        $folders = $this->resolveFolders();

        if ($folders->isEmpty()) {
            $this->warn('No matching folder found.');

            return self::FAILURE;
        }

        $full = (bool) $this->option('full');

        foreach ($folders as $folder) {
            $run = $runner->start($folder, $full, trigger: 'cli');

            $this->line(sprintf(
                '  %s — %s (%s)',
                $folder->instance->name,
                $folder->label,
                $run->wasRecentlyCreated ? 'started' : 'already running',
            ));
        }

        $this->info(sprintf(
            '%d folder(s) queued%s. Progress: php artisan nextsearch:status',
            $folders->count(),
            $full ? ' (full)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, WatchedFolder>
     */
    private function resolveFolders()
    {
        $query = WatchedFolder::query()->with('instance')->where('enabled', true);

        if ($folderUuid = $this->option('folder')) {
            $query->where('uuid', $folderUuid);
        }

        if ($instance = $this->option('instance')) {
            $instanceId = NextcloudInstance::query()
                ->where('uuid', $instance)
                ->orWhere('name', $instance)
                ->value('id');

            $query->where('nextcloud_instance_id', $instanceId);
        }

        return $query->get();
    }
}
