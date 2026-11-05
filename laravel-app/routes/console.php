<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Video recipes from YouTube: new videos every hour, statistics of the whole channel once a day.
Schedule::command('videos:sync --latest')->hourly()->withoutOverlapping();
Schedule::command('videos:sync')->dailyAt('04:00')->withoutOverlapping();

// Static site: rebuilt a few minutes after WordPress content changes, and every night, so view
// counts, ratings and new videos reach the pages (App\Services\SiteRebuild).
Schedule::command('site:rebuild')->everyMinute()->withoutOverlapping();
Schedule::command('site:rebuild --force')->dailyAt('04:30')->withoutOverlapping();
