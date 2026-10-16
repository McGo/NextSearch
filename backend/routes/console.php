<?php

use App\Services\Indexing\IndexRunner;
use App\Services\OAuth\OAuthServer;
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

// Automatic tags have a shelf life. Five minutes is fine: nobody sets "Neu" to
// a duration where a few minutes either way matter.
Schedule::command('nextsearch:tags-sweep')
    ->everyFiveMinutes()
    ->name('nextsearch:tags-sweep')
    ->withoutOverlapping();

// Documents that disappeared from their directory and were not found again
// elsewhere within the retention period are deleted for good.
Schedule::command('nextsearch:purge-vanished')
    ->daily()
    ->name('nextsearch:purge-vanished')
    ->withoutOverlapping();

// Sweep the index for documents that no longer exist in the database — a safety
// net for orphans that a deletion's index cleanup missed. Interactive access
// heals them on the spot; this catches the rest.
Schedule::command('nextsearch:reconcile')
    ->daily()
    ->name('nextsearch:reconcile')
    ->withoutOverlapping();

// Spent authorization codes and long-dead OAuth tokens. Nothing depends on this
// running — an expired token is refused either way — it only keeps the tables
// from growing for no reason.
Schedule::call(fn (OAuthServer $server) => $server->prune())
    ->daily()
    ->name('nextsearch:oauth-prune')
    ->withoutOverlapping();
