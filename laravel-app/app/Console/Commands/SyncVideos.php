<?php

namespace App\Console\Commands;

use App\Services\Videos;
use App\Services\YouTube;
use Illuminate\Console\Command;

/** Imports new videos of the YouTube channel into WordPress and refreshes views/likes/comments. */
class SyncVideos extends Command
{
    protected $signature = 'videos:sync {--latest : Only the newest 50 videos (API), for the frequent check}';

    protected $description = 'Import video recipes from YouTube into WordPress (post type "video")';

    public function handle(YouTube $youtube, Videos $videos): int
    {
        if (! $youtube->hasApiKey()) {
            $this->warn('YOUTUBE_API_KEY is not set: only the latest 15 videos (RSS feed) are synced.');
        }
        [$added, $updated] = $videos->sync($youtube->videos(all: ! $this->option('latest')));
        $this->info("Videos: $added added, $updated updated.");
        // New videos need pages: the site rebuilds a few minutes later. Statistics wait for the nightly build.
        if ($added > 0) {
            app(\App\Services\SiteRebuild::class)->contentChanged("$added new videos from YouTube");
        }

        return self::SUCCESS;
    }
}
