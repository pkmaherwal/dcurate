<?php

use App\Models\FetchRun;
use Illuminate\Support\Facades\Schedule;

Schedule::command('curation:dispatch-due')->everyMinute()->withoutOverlapping()->onOneServer();

Schedule::command('curation:enrich-pending')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('model:prune', ['--model' => [FetchRun::class]])->daily();

Schedule::command('horizon:snapshot')->everyFiveMinutes()
    ->when(fn () => config('queue.default') === 'redis');
