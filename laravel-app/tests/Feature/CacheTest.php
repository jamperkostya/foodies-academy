<?php

namespace Tests\Feature;

use App\Services\SiteRebuild;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\SiteTestCase;

class CacheTest extends SiteTestCase
{
    protected function tearDown(): void
    {
        File::delete(storage_path('app/site-rebuild.json'));
        parent::tearDown();
    }

    private function contentChanged(): void
    {
        $this->withHeader('X-Foodies-Secret', config('services.wordpress.api_secret'))
            ->postJson('/api/internal/content-changed', ['reason' => 'recipe edited'])
            ->assertStatus(202);
    }

    public function test_answers_come_from_the_cache_until_wordpress_reports_a_change(): void
    {
        $id = $this->recipe('borscht', ['post_title' => 'Борщ']);
        $this->getJson('/api/recipes/borscht')->assertJsonPath('data.title', 'Борщ');

        // Edited straight in the database: the cached answer stays.
        $this->site()->table('posts')->where('ID', $id)->update(['post_title' => 'Борщ украинский']);
        $this->getJson('/api/recipes/borscht')->assertJsonPath('data.title', 'Борщ');

        $this->contentChanged();
        $this->getJson('/api/recipes/borscht')->assertJsonPath('data.title', 'Борщ украинский');
    }

    public function test_a_change_clears_every_cached_list_and_queues_a_build(): void
    {
        $this->recipe('borscht');
        foreach (['/api/recipes/new', '/api/recipes/slugs', '/api/stats', '/api/blog', '/api/news', '/api/lifehacks', '/api/videos'] as $url) {
            $this->getJson($url)->assertOk();
        }
        $this->recipe('pasta', ['post_date' => '2026-02-01 10:00:00']);
        $this->getJson('/api/stats')->assertJsonPath('data.recipes', 1);

        $this->contentChanged();

        $this->getJson('/api/stats')->assertJsonPath('data.recipes', 2);
        $this->getJson('/api/recipes/slugs')->assertJsonCount(2, 'data');
        $this->getJson('/api/recipes/new')->assertJsonPath('data.0.slug', 'pasta');
        // The build request survives the cache flush (it's a file, not a cache entry).
        $this->assertSame(['recipe edited'], app(SiteRebuild::class)->pending()['reasons']);
    }

    public function test_the_api_keeps_working_when_the_cache_database_is_down(): void
    {
        // The site's store: the database, with memory as the fallback.
        config(['cache.default' => 'failover']);
        Cache::forgetDriver('failover');
        Schema::drop('cache');
        $this->recipe('borscht');

        $this->getJson('/api/recipes/borscht')->assertOk();
        $this->getJson('/api/stats')->assertOk()->assertJsonPath('data.recipes', 1);
        $this->contentChanged();
    }

    public function test_the_site_uses_the_failover_cache(): void
    {
        // laravel-app/.env.example documents the setting the site runs with.
        $this->assertStringContainsString('CACHE_STORE=failover', file_get_contents(base_path('.env.example')));
        $this->assertSame(['database', 'array'], config('cache.stores.failover.stores'));
    }
}
