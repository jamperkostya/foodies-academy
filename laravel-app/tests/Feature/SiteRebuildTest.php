<?php

namespace Tests\Feature;

use App\Services\SiteRebuild;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\SiteTestCase;

class SiteRebuildTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        File::delete(storage_path('app/site-rebuild.json'));
        config(['services.github.repo' => 'owner/site', 'services.github.token' => 'token']);
    }

    protected function tearDown(): void
    {
        File::delete(storage_path('app/site-rebuild.json'));
        parent::tearDown();
    }

    private function report(string $reason = 'recipe borscht: publish → publish')
    {
        return $this->withHeader('X-Foodies-Secret', config('services.wordpress.api_secret'))
            ->postJson('/api/internal/content-changed', ['reason' => $reason]);
    }

    public function test_wordpress_reports_a_change_with_the_shared_key(): void
    {
        Cache::put('recipes:top:5', 'stale', 600);

        $this->postJson('/api/internal/content-changed')->assertForbidden();
        $this->report()->assertStatus(202);

        // The API answers from fresh data at once.
        $this->assertNull(Cache::get('recipes:top:5'));
        $this->assertSame(['recipe borscht: publish → publish'], app(SiteRebuild::class)->pending()['reasons']);
    }

    public function test_a_batch_of_edits_is_built_once_after_five_quiet_minutes(): void
    {
        Http::fake(['api.github.com/*' => Http::response(null, 204)]);
        Carbon::setTestNow('2026-11-03 12:00:00');
        $this->report('edit 1');
        Carbon::setTestNow('2026-11-03 12:03:00');
        $this->report('edit 2');

        Carbon::setTestNow('2026-11-03 12:07:59');
        $this->artisan('site:rebuild')->assertSuccessful();
        Http::assertNothingSent();

        Carbon::setTestNow('2026-11-03 12:08:00');
        $this->artisan('site:rebuild')->expectsOutputToContain('started')->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/repos/owner/site/dispatches'
            && $request['event_type'] === 'content-changed'
            && $request['client_payload']['changes'] === ['edit 1', 'edit 2']
            && $request->hasHeader('Authorization', 'Bearer token'));

        // Nothing left to build.
        $this->artisan('site:rebuild')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_production_asks_for_its_own_build(): void
    {
        Http::fake(['api.github.com/*' => Http::response(null, 204)]);
        config(['services.github.event' => 'content-changed-production']);

        $this->artisan('site:rebuild', ['--force' => true])->assertSuccessful();

        Http::assertSent(fn ($request) => $request['event_type'] === 'content-changed-production');
    }

    public function test_constant_edits_still_build_within_30_minutes(): void
    {
        Http::fake(['api.github.com/*' => Http::response(null, 204)]);
        for ($minute = 0; $minute <= 30; $minute += 2) {
            Carbon::setTestNow(Carbon::parse('2026-11-03 12:00:00')->addMinutes($minute));
            $this->report("edit at $minute");
            $this->artisan('site:rebuild');
        }

        Http::assertSentCount(1);
    }

    public function test_a_failed_dispatch_is_retried(): void
    {
        Http::fake(['api.github.com/*' => Http::sequence()->push('bad credentials', 401)->push(null, 204)]);
        $this->report();

        $this->expectException(\RuntimeException::class);
        try {
            app(SiteRebuild::class)->trigger('test');
        } finally {
            $this->assertNotNull(app(SiteRebuild::class)->pending());
        }
    }

    public function test_nightly_build_and_no_github_settings(): void
    {
        Http::fake(['api.github.com/*' => Http::response(null, 204)]);
        $this->artisan('site:rebuild --force')->expectsOutputToContain('started');

        config(['services.github.token' => null]);
        $this->artisan('site:rebuild --force')->expectsOutputToContain('skipped');
        Http::assertSentCount(1);
    }
}
