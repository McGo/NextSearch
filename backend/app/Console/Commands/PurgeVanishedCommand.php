<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Search\SearchIndex;
use Illuminate\Console\Command;

/**
 * Deletes documents that disappeared from their directory and did not turn up
 * anywhere else within the retention period. Until then they wait as vanished,
 * so a file that is moved keeps its uuid and tags whatever order the crawls
 * run in.
 */
class PurgeVanishedCommand extends Command
{
    protected $signature = 'nextsearch:purge-vanished';

    protected $description = 'Delete documents that have stayed vanished longer than the retention period';

    public function handle(SearchIndex $index): int
    {
        $cutoff = now()->subDays((int) config('nextsearch.index.vanished_retention_days'));
        $deleted = 0;

        Document::query()
            ->withVanished()
            ->whereNotNull('vanished_at')
            ->where('vanished_at', '<', $cutoff)
            ->chunkById(500, function ($documents) use ($index, &$deleted) {
                // They left the index when they vanished. Once more costs
                // nothing and catches one a failed request left behind.
                $index->forget($documents->pluck('uuid')->all());
                Document::query()->withVanished()->whereIn('id', $documents->pluck('id'))->delete();
                $deleted += $documents->count();
            });

        $this->info(sprintf('%d vanished document(s) deleted.', $deleted));

        return self::SUCCESS;
    }
}
