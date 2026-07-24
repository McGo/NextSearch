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
        {--instance= : UUID oder Name einer Instanz}
        {--folder= : UUID eines überwachten Ordners}
        {--full : Delta-Erkennung übergehen und alles neu verarbeiten}
        {--due-only : Nur Ordner, deren Intervall abgelaufen ist}';

    protected $description = 'Überwachte Ordner durchlaufen und indizieren';

    public function handle(IndexRunner $runner): int
    {
        if ($this->option('due-only')) {
            $runs = $runner->startDue();
            $this->info(sprintf('%d Durchlauf/Durchläufe angestoßen.', count($runs)));

            return self::SUCCESS;
        }

        $folders = $this->resolveFolders();

        if ($folders->isEmpty()) {
            $this->warn('Kein passender Ordner gefunden.');

            return self::FAILURE;
        }

        $full = (bool) $this->option('full');

        foreach ($folders as $folder) {
            $run = $runner->start($folder, $full, trigger: 'cli');

            $this->line(sprintf(
                '  %s — %s (%s)',
                $folder->instance->name,
                $folder->label,
                $run->wasRecentlyCreated ? 'gestartet' : 'läuft bereits',
            ));
        }

        $this->info(sprintf(
            '%d Ordner in der Warteschlange%s. Fortschritt: php artisan nextsearch:status',
            $folders->count(),
            $full ? ' (vollständig)' : '',
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
