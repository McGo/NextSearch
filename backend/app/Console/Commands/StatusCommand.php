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

    protected $description = 'Zustand von Instanzen, Index und Warteschlange anzeigen';

    public function handle(SearchIndex $index, TikaClient $tika): int
    {
        $this->components->twoColumnDetail('<fg=gray>Dienst</>', '<fg=gray>Zustand</>');
        $this->components->twoColumnDetail('Tika', $tika->isReachable() ? '<fg=green>erreichbar</>' : '<fg=red>nicht erreichbar</>');
        $this->components->twoColumnDetail('Suchindex', number_format((float) ($index->stats()['numberOfDocuments'] ?? 0), 0, ',', '.').' Dokumente');

        foreach (['crawl', 'process', 'preview'] as $queue) {
            $this->components->twoColumnDetail('Warteschlange '.$queue, (string) Queue::size($queue));
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Instanz</>', '<fg=gray>Verbindung</>');

        foreach (NextcloudInstance::query()->withCount('folders')->get() as $instance) {
            $this->components->twoColumnDetail(
                sprintf('%s (%d Ordner)', $instance->name, $instance->folders_count),
                match ($instance->health_state) {
                    NextcloudInstance::HEALTH_OK => '<fg=green>ok</>',
                    NextcloudInstance::HEALTH_FAILED => '<fg=red>Fehler</>',
                    default => '<fg=yellow>ungeprüft</>',
                },
            );
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Dokumente</>', '<fg=gray>Anzahl</>');

        foreach (Document::query()->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state') as $state => $total) {
            $this->components->twoColumnDetail($state, (string) $total);
        }

        $runs = IndexRun::query()->with('folder')->latest('id')->limit(5)->get();

        if ($runs->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['Ordner', 'Zustand', 'gesehen', 'neu', 'geändert', 'entfernt', 'Fehler', 'offen'],
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
