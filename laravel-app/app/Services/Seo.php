<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SEO fields set in Yoast SEO, read from the WordPress REST API (yoast_head_json), for the
 * site's <head>: {title, description, index, follow, og: {...}, twitter: {...}}, or null when
 * WordPress doesn't answer (the page then uses its own title and description).
 * Canonical and og:url are left out: Yoast builds them from WordPress addresses, the site
 * (Astro) knows its own.
 */
class Seo
{
    private const CACHE_MINUTES = 30;

    /** $restBase: recipe, video, posts, pages. */
    public function forPost(string $restBase, string $slug): ?array
    {
        return $this->fetch($restBase, ['slug' => $slug]);
    }

    /** $taxonomy: course, cuisine, category. */
    public function forTerm(string $taxonomy, string $slug): ?array
    {
        return $this->fetch($taxonomy, ['slug' => $slug]);
    }

    public function forUser(string $slug): ?array
    {
        return $this->fetch('users', ['slug' => $slug]);
    }

    public function forPageId(int $id): ?array
    {
        return $this->fetch("pages/$id");
    }

    // Query parameters as an array: Http::get() replaces a query string written in the URL.
    private function fetch(string $path, array $query = []): ?array
    {
        $key = 'seo:'.$path.':'.http_build_query($query);

        return Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), function () use ($path, $query) {
            try {
                $url = rtrim(config('services.wordpress.url'), '/')."/wp-json/wp/v2/$path";
                $response = Http::timeout(10)->acceptJson()->get($url, $query + ['_fields' => 'yoast_head_json']);
                if (! $response->successful()) {
                    return null;
                }
                $head = $response->json('0.yoast_head_json') ?? $response->json('yoast_head_json');

                return is_array($head) ? $this->normalize($head) : null;
            } catch (Throwable $e) {
                report($e);

                return null;
            }
        });
    }

    private function normalize(array $head): array
    {
        $text = fn ($value) => is_string($value) && trim($value) !== '' ? trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5)) : null;
        $image = $head['og_image'][0] ?? null;

        return [
            'title' => $text($head['title'] ?? null),
            'description' => $text($head['description'] ?? null),
            'index' => ($head['robots']['index'] ?? 'index') !== 'noindex',
            'follow' => ($head['robots']['follow'] ?? 'follow') !== 'nofollow',
            'og' => [
                'title' => $text($head['og_title'] ?? null),
                'description' => $text($head['og_description'] ?? null),
                'type' => $text($head['og_type'] ?? null),
                'image' => $image && ! empty($image['url']) ? [
                    'url' => $image['url'],
                    'width' => (int) ($image['width'] ?? 0) ?: null,
                    'height' => (int) ($image['height'] ?? 0) ?: null,
                    'type' => $text($image['type'] ?? null),
                ] : null,
                'publishedTime' => $head['article_published_time'] ?? null,
                'modifiedTime' => $head['article_modified_time'] ?? null,
                'siteName' => $text($head['og_site_name'] ?? null),
            ],
            'twitter' => [
                'card' => $text($head['twitter_card'] ?? null) ?? 'summary_large_image',
                'title' => $text($head['twitter_title'] ?? null),
                'description' => $text($head['twitter_description'] ?? null),
                'image' => $text($head['twitter_image'] ?? null),
            ],
        ];
    }
}
