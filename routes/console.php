<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keeps the ref cache from going more than half a day stale even if nobody
// clicks "Fetch latest" in the UI.
Schedule::command('mwdeploy:rebuild-git-cache')->cron('0 */12 * * *')->withoutOverlapping();

// File-browser cache entries are cheap to rebuild and unbounded in number of
// commits ever browsed, so they get a much shorter TTL than the ref cache.
Schedule::command('mwdeploy:prune-git-file-cache')->hourly()->withoutOverlapping();
