<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Rebuilds the static site after WordPress content changes. WordPress reports each change
 * (wp-content/mu-plugins/foodies-rebuild.php); the build starts once nothing has changed for
 * DEBOUNCE_MINUTES (so a batch of edits is one build), and at the latest MAX_WAIT_MINUTES after the
 * first change. The build itself is the GitHub Actions workflow (.github/workflows/deploy.yml).
 */
class SiteRebuild
{
    // A build takes ~25 minutes and loads the server: a batch of edits waits for 10 quiet minutes.
    private const DEBOUNCE_MINUTES = 10;

    private const MAX_WAIT_MINUTES = 60;

    /** {first, last, reasons} of the changes not built yet. A file: Cache::flush() must not lose it. */
    private function path(): string
    {
        return storage_path('app/site-rebuild.json');
    }

    public function contentChanged(string $reason): void
    {
        // The API answers from fresh data at once; the static pages follow after the build.
        // The Yoast fields are in their own store: only the edited post's are forgotten (Seo::forget()).
        Cache::flush();

        $now = Carbon::now()->toIso8601String();
        $state = $this->pending() ?? ['first' => $now, 'reasons' => []];
        $state['last'] = $now;
        $state['reasons'] = array_slice([...$state['reasons'], $reason], -20);
        File::put($this->path(), json_encode($state, JSON_UNESCAPED_UNICODE));
    }

    public function pending(): ?array
    {
        return File::exists($this->path()) ? json_decode(File::get($this->path()), true) : null;
    }

    /** Whether the pending changes should be built now. */
    public function isDue(): bool
    {
        $state = $this->pending();
        if (! $state) {
            return false;
        }

        return ! Carbon::parse($state['last'])->addMinutes(self::DEBOUNCE_MINUTES)->isFuture()
            || ! Carbon::parse($state['first'])->addMinutes(self::MAX_WAIT_MINUTES)->isFuture();
    }

    /**
     * Starts the build. Without GitHub settings (local docker) it only logs: the dev server
     * renders pages on request anyway.
     */
    public function trigger(string $reason): string
    {
        $repo = config('services.github.repo');
        $token = config('services.github.token');
        $pending = $this->pending();
        File::delete($this->path());

        if (! $repo || ! $token) {
            Log::info('Site rebuild skipped: GITHUB_DEPLOY_REPO / GITHUB_DEPLOY_TOKEN not set', ['reason' => $reason, 'changes' => $pending['reasons'] ?? []]);

            return 'skipped (no GitHub settings)';
        }

        $response = Http::timeout(15)->withToken($token)->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->post("https://api.github.com/repos/$repo/dispatches", [
                'event_type' => config('services.github.event'),
                'client_payload' => ['reason' => $reason, 'changes' => array_slice($pending['reasons'] ?? [], -10)],
            ]);
        if (! $response->successful()) {
            // Keep the changes, so the next run tries again.
            if ($pending) {
                File::put($this->path(), json_encode($pending, JSON_UNESCAPED_UNICODE));
            }
            throw new RuntimeException("GitHub dispatch failed: HTTP {$response->status()} {$response->body()}");
        }

        return 'started';
    }
}
