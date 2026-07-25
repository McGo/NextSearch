<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Search\SearchIndex;
use Illuminate\Console\Command;

class ReconcileCommand extends Command
{
    protected $signature = 'nextsearch:reconcile';

    protected $description = 'Remove documents from the search index that no longer exist in the database';

    public function handle(SearchIndex $index): int
    {
        // The ids the database knows about, in index form (uuid without dashes).
        $known = array_flip(
            Document::query()
                ->pluck('uuid')
                ->map(fn (string $uuid) => str_replace('-', '', $uuid))
                ->all(),
        );

        $orphans = [];
        $offset = 0;
        $limit = 1000;

        do {
            $ids = $index->documentIdPage($offset, $limit);

            foreach ($ids as $id) {
                if (! isset($known[$id])) {
                    $orphans[] = $id;
                }
            }

            $offset += $limit;
        } while (count($ids) === $limit);

        $index->deleteByIds($orphans);

        $this->info(sprintf('%d orphaned document(s) removed from the index.', count($orphans)));

        return self::SUCCESS;
    }
}
