<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/** Ingredients (the WP Ultimate Recipe "ingredient" taxonomy) and their recipes. */
class Ingredients
{
    private ConnectionInterface $db;

    public function __construct(private Recipes $recipes)
    {
        $this->db = DB::connection('site');
    }

    /** The ingredients used in the most recipes. */
    public function getPopular(int $limit = 5): array
    {
        return $this->db->table('term_taxonomy as tt')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'ingredient')
            ->where('tt.count', '>', 0)
            ->orderByDesc('tt.count')
            ->limit($limit)
            ->get(['t.name', 't.slug', 'tt.count'])
            ->map(fn ($term) => [
                'name' => html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5),
                'slug' => $term->slug,
                'count' => (int) $term->count,
            ])
            ->all();
    }

    /** One ingredient with one page of its recipes, most viewed first, or null. */
    public function getBySlug(string $slug, int $page = 1, int $perPage = 24): ?array
    {
        $term = $this->db->table('term_taxonomy as tt')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'ingredient')
            ->where('t.slug', $slug)
            ->first(['tt.term_taxonomy_id', 't.name', 't.slug', 'tt.count']);
        if (! $term) {
            return null;
        }

        $posts = $this->db->table('posts as p')
            ->join('icl_translations as tr', function ($join) {
                $join->on('tr.element_id', '=', 'p.ID')
                    ->where('tr.element_type', 'post_recipe')
                    ->where('tr.language_code', 'ru');
            })
            ->join('term_relationships as r', function ($join) use ($term) {
                $join->on('r.object_id', '=', 'p.ID')->where('r.term_taxonomy_id', $term->term_taxonomy_id);
            })
            ->leftJoin('post_views_realtime as v', function ($join) {
                $join->on('v.post_id', '=', 'p.ID')
                    ->where('v.view_type', 'normal')
                    ->where('v.output_type', 'content');
            })
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            ->orderByDesc('v.post_views_total');

        return [
            'name' => html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5),
            'slug' => $term->slug,
            'count' => (int) $term->count,
        ] + $this->recipes->paginate($posts, $page, $perPage);
    }
}
