<?php

namespace App\Console\Commands;

use App\Services\Tags\TagService;
use Illuminate\Console\Command;

/**
 * Removes the tag assignments whose time is up — in practice "Neu" on documents
 * that have stopped being new. Runs on a schedule; running it by hand is the
 * quickest way to check a duration setting.
 */
class SweepTagsCommand extends Command
{
    protected $signature = 'nextsearch:tags-sweep';

    protected $description = 'Drop expired tag assignments and update the search index';

    public function handle(TagService $tags): int
    {
        $removed = $tags->sweepExpired();

        $this->info($removed === 0
            ? 'Nothing has expired.'
            : sprintf('%d assignment(s) removed.', $removed));

        return self::SUCCESS;
    }
}
