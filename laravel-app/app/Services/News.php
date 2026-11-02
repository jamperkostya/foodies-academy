<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * News from the foodies.academy WordPress database: published Russian posts (post_type = post).
 * Every method returns arrays that match the News type in astro-app/src/types/news.ts.
 */
class News
{
    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    /** Newest news first. */
    public function getLatest(int $limit = 7): array
    {
        return $this->present($this->query()->orderByDesc('p.post_date')->limit($limit)->get(['p.*']));
    }

    /** Slugs of all news, for building the news pages. */
    public function getSlugs(): array
    {
        return $this->query()->orderByDesc('p.post_date')->pluck('p.post_name')->all();
    }

    /** One news item with its HTML content, or null. */
    public function getBySlug(string $slug): ?array
    {
        $post = $this->query()->where('p.post_name', $slug)->first(['p.*']);
        if (! $post) {
            return null;
        }

        return $this->present(collect([$post]))[0] + ['content' => $post->post_content];
    }

    private function query(): Builder
    {
        return $this->db->table('posts as p')
            ->join('icl_translations as tr', function ($join) {
                $join->on('tr.element_id', '=', 'p.ID')
                    ->where('tr.element_type', 'post_post')
                    ->where('tr.language_code', 'ru');
            })
            ->where('p.post_type', 'post')
            ->where('p.post_status', 'publish');
    }

    /** post_id => [taxonomy => [names]] for the category and tags of the posts. */
    private function terms(array $ids): Collection
    {
        return $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->whereIn('r.object_id', $ids)
            ->whereIn('tt.taxonomy', ['category', 'post_tag'])
            ->orderBy('r.term_order')
            ->get(['r.object_id', 'tt.taxonomy', 't.name'])
            ->groupBy(['object_id', 'taxonomy'])
            ->map(fn ($byTaxonomy) => $byTaxonomy->map(fn ($rows) => $rows
                ->map(fn ($row) => html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5))
                ->all())->all());
    }

    private function present(Collection $posts): array
    {
        if ($posts->isEmpty()) {
            return [];
        }
        $ids = $posts->pluck('ID')->all();
        $terms = $this->terms($ids);
        $thumbs = $this->db->table('postmeta')
            ->whereIn('post_id', $ids)
            ->where('meta_key', '_thumbnail_id')
            ->pluck('meta_value', 'post_id');
        $images = app(Attachments::class)->urls($thumbs->map(fn ($id) => (int) $id)->filter()->all());

        return $posts->map(fn ($post) => [
            'id' => (int) $post->ID,
            'slug' => $post->post_name,
            'title' => html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5),
            'excerpt' => $this->excerpt($post),
            'category' => $terms[$post->ID]['category'][0] ?? '',
            'tags' => $terms[$post->ID]['post_tag'] ?? [],
            'image' => $images[(int) ($thumbs[$post->ID] ?? 0)] ?? '',
            'date' => substr($post->post_date, 0, 10),
        ])->values()->all();
    }

    /** The post's own excerpt, or the start of its text (~160 characters, cut at a word). */
    private function excerpt(object $post): string
    {
        $text = trim($post->post_excerpt) ?: $post->post_content;
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)));
        if (mb_strlen($text) <= 160) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, mb_strrpos(mb_substr($text, 0, 160), ' ') ?: 160), ' ,.;:—-').'…';
    }
}
