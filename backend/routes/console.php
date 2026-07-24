<?php

use App\Services\Indexing\IndexRunner;
use Illuminate\Support\Facades\Schedule;

// Minütlich prüfen, welcher Ordner sein Intervall überschritten hat oder
// manuell angefordert wurde. Was fällig ist, entscheidet der Runner.
Schedule::call(fn (IndexRunner $runner) => $runner->startDue())
    ->everyMinute()
    ->name('nextsearch:due')
    ->withoutOverlapping();

// Läufe, die ein Neustart mitten im Durchlauf erwischt hat, blieben sonst für
// immer auf „running" stehen und blockierten weitere Durchläufe.
Schedule::call(fn (IndexRunner $runner) => $runner->reapStale())
    ->hourly()
    ->name('nextsearch:reap');
