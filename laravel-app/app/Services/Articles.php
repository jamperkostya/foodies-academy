<?php

namespace App\Services;

use App\Support\WpHtml;
use App\Support\YouTubeUrl;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Published WordPress articles of one post type: news (post_type "news") and the blog (ordinary
 * posts). Arrays match the Article type in astro-app/src/types/article.ts.
 */
abstract class Articles
{
    /** WordPress post type. */
    protected const POST_TYPE = '';

    /** Its category taxonomy. */
    protected const CATEGORY = '';

    /** Its WordPress REST base, for Yoast SEO. */
    protected const REST_BASE = '';

    protected ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    /** Newest first. */
    public function getLatest(int $limit = 7): array
    {
        return $this->present($this->query()->orderByDesc('p.post_date')->limit($limit)->get(['p.*']));
    }

    /** Written by one WordPress user, newest first. */
    public function getByAuthor(int $userId): array
    {
        return $this->present($this->query()->where('p.post_author', $userId)->orderByDesc('p.post_date')->get(['p.*']));
    }

    /** Slugs of all of them, for building the pages. */
    public function getSlugs(): array
    {
        return $this->query()->orderByDesc('p.post_date')->pluck('p.post_name')->all();
    }

    public function getId(string $slug): ?int
    {
        $id = $this->query()->where('p.post_name', $slug)->value('p.ID');

        return $id ? (int) $id : null;
    }

    /** One article with its HTML, approved comments, SEO text and Yoast fields, or null. */
    public function getBySlug(string $slug): ?array
    {
        $post = $this->query()->where('p.post_name', $slug)->first(['p.*']);
        if (! $post) {
            return null;
        }

        return $this->present(collect([$post]))[0] + [
            'content' => WpHtml::render($post->post_content),
            'comments' => app(Comments::class)->getForPost($post->ID),
            'seoText' => app(SeoText::class)->forPost($post->ID),
            'seo' => app(Seo::class)->forPost(static::REST_BASE, $post->post_name),
        ];
    }

    protected function query(): Builder
    {
        return $this->db->table('posts as p')
            ->where('p.post_type', static::POST_TYPE)
            ->where('p.post_status', 'publish');
    }

    /** post_id => [taxonomy => [names]] for the category and tags of the posts. */
    private function terms(array $ids): Collection
    {
        return $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->whereIn('r.object_id', $ids)
            ->whereIn('tt.taxonomy', [static::CATEGORY, 'post_tag'])
            ->orderBy('r.term_order')
            ->get(['r.object_id', 'tt.taxonomy', 't.name'])
            ->groupBy(['object_id', 'taxonomy'])
            ->map(fn ($byTaxonomy) => $byTaxonomy->map(fn ($rows) => $rows
                ->map(fn ($row) => html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5))
                ->all())->all());
    }

    protected function present(Collection $posts): array
    {
        if ($posts->isEmpty()) {
            return [];
        }
        $ids = $posts->pluck('ID')->all();
        $terms = $this->terms($ids);
        $meta = $this->db->table('postmeta')
            ->whereIn('post_id', $ids)
            ->whereIn('meta_key', ['_thumbnail_id', 'link_to_video'])
            ->get(['post_id', 'meta_key', 'meta_value'])
            ->groupBy('post_id')
            ->map(fn ($rows) => $rows->pluck('meta_value', 'meta_key'));
        // The cover: the featured image, or else the first image of the text from the site's own media
        // library (many old posts have no featured image).
        $thumbs = $posts->mapWithKeys(fn ($post) => [
            $post->ID => (int) ($meta[$post->ID]['_thumbnail_id'] ?? 0)
                ?: (preg_match('/wp-image-(\d+)/', $post->post_content, $m) ? (int) $m[1] : 0),
        ]);
        $images = app(Attachments::class)->urls($thumbs->filter()->values()->all());
        $users = $this->db->table('users')->whereIn('ID', $posts->pluck('post_author'))->get(['ID', 'user_nicename', 'display_name'])->keyBy('ID');

        return $posts->map(function ($post) use ($terms, $meta, $thumbs, $images, $users) {
            $user = $users->get($post->post_author);

            return [
                'id' => (int) $post->ID,
                'slug' => $post->post_name,
                'title' => html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5),
                'excerpt' => $this->excerpt($post),
                'category' => $terms[$post->ID][static::CATEGORY][0] ?? '',
                'tags' => $terms[$post->ID]['post_tag'] ?? [],
                'image' => $images[$thumbs[$post->ID]] ?? $this->videoCover((string) ($meta[$post->ID]['link_to_video'] ?? '')),
                'date' => substr($post->post_date, 0, 10),
                'dateText' => Carbon::parse($post->post_date)->locale('ru')->translatedFormat('j F Y'),
                'author' => [
                    'slug' => $user->user_nicename ?? '',
                    'name' => $user ? html_entity_decode($user->display_name, ENT_QUOTES | ENT_HTML5) : 'Foodies Academy',
                ],
                // About 180 words a minute.
                'readTime' => max(1, (int) round(count(preg_split('/\s+/u', trim(strip_tags($post->post_content)), -1, PREG_SPLIT_NO_EMPTY)) / 180)),
                'commentCount' => (int) $post->comment_count,
            ];
        })->values()->all();
    }

    /** Preview of the post's YouTube video ("link_to_video"), "" without one. */
    private function videoCover(string $url): string
    {
        $id = YouTubeUrl::id($url);

        return $id ? "https://i.ytimg.com/vi/$id/hqdefault.jpg" : '';
    }

    /** The post's own excerpt, or the start of its text (~160 characters, cut at a word). */
    private function excerpt(object $post): string
    {
        $text = trim($post->post_excerpt) ?: preg_replace('/<!--[\s\S]*?-->/', '', $post->post_content);
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)));
        if (mb_strlen($text) <= 160) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, mb_strrpos(mb_substr($text, 0, 160), ' ') ?: 160), ' ,.;:—-').'…';
    }
}
