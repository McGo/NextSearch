<?php

use App\Services\Indexing\IndexRunner;
use Illuminate\Support\Facades\Schedule;

// Check every minute which folder has passed its interval or was requested
// manually. What is due is the runner's decision.
Schedule::call(fn (IndexRunner $runner) => $runner->startDue())
    ->everyMinute()
    ->name('nextsearch:due')
    ->withoutOverlapping();

// Runs caught mid-crawl by a restart would otherwise stay on "running" forever
// and block further runs.
Schedule::call(fn (IndexRunner $runner) => $runner->reapStale())
    ->hourly()
    ->name('nextsearch:reap');

// Sweep the index for documents that no longer exist in the database — a safety
// net for orphans that a deletion's index cleanup missed. Interactive access
// heals them on the spot; this catches the rest.
Schedule::command('nextsearch:reconcile')
    ->daily()
    ->name('nextsearch:reconcile')
    ->withoutOverlapping();
