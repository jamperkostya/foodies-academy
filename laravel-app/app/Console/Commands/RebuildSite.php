<?php

namespace App\Console\Commands;

use App\Services\SiteRebuild;
use Illuminate\Console\Command;

class RebuildSite extends Command
{
    protected $signature = 'site:rebuild {--force : Build now, even without content changes (nightly build)}';

    protected $description = 'Rebuild the static site when WordPress content changed';

    public function handle(SiteRebuild $rebuild): int
    {
        if (! $this->option('force') && ! $rebuild->isDue()) {
            return self::SUCCESS;
        }
        // Pages with bad addresses wouldn't build: say so in the log, but don't hold the rest back.
        if ($this->callSilently('slugs:check') !== self::SUCCESS) {
            \Illuminate\Support\Facades\Log::warning('Site rebuild: some slugs need fixing, see php artisan slugs:check');
        }
        $this->info('Site rebuild: '.$rebuild->trigger($this->option('force') ? 'nightly' : 'content changed'));

        return self::SUCCESS;
    }
}
