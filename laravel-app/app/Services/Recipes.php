<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recipes from the foodies.academy WordPress database.
 * Every method returns arrays that match the Recipe type in astro-app/src/types/recipe.ts.
 */
class Recipes
{
    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    /** Most liked recipes (the like_post meta of the like button plugin). */
    public function getTopRecipes(int $limit = 10): array
    {
        $posts = $this->db->table('posts')->where('post_type', 'recipe')->where('post_status', 'publish')->limit($limit)->get();
        dd($this->present($posts));
        return $this->present($posts);
    }

    /** attachment_id => URL of the 601px "medium" size (or the original) */
    private function images(array $attachmentIds): array
    {
        if (! $attachmentIds) {
            return [];
        }
        $base = rtrim(config('services.academy.uploads_url'), '/');
        return $this->db->table('postmeta')
            ->whereIn('post_id', $attachmentIds)
            ->where('meta_key', '_wp_attachment_metadata')
            ->pluck('meta_value', 'post_id')
            ->map(function ($value) use ($base) {
                $data = @unserialize($value, ['allowed_classes' => false]);
                if (! is_array($data) || empty($data['file'])) {
                    return '';
                }
                $dir = dirname($data['file']);
                $file = $data['sizes']['medium']['file'] ?? basename($data['file']);

                return $base.'/'.($dir === '.' ? '' : $dir.'/').rawurlencode($file);
            })
            ->all();
    }

    /** post_id => [meta_key => value] */
    private function meta(array $ids): Collection
    {
        return $this->db->table('postmeta')
            ->whereIn('post_id', $ids)
            ->whereIn('meta_key', [
                '_thumbnail_id', 'recipe_rating', '_yoast_wpseo_primary_course',
                'recipe_prep_time', 'recipe_prep_time_text',
                'recipe_cook_time', 'recipe_cook_time_text',
                'recipe_passive_time', 'recipe_passive_time_text',
            ])
            ->get(['post_id', 'meta_key', 'meta_value'])
            ->groupBy('post_id')
            ->map(fn ($rows) => $rows->pluck('meta_value', 'meta_key')->all());
    }

    //     /** post_id => [taxonomy => [term_id => name]] */
    private function terms(array $ids): Collection
    {
        return $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->whereIn('r.object_id', $ids)
            ->whereIn('tt.taxonomy', ['course', 'ingredient'])
            ->orderBy('r.term_order')
            ->get(['r.object_id', 'tt.taxonomy', 't.term_id', 't.name'])
            ->groupBy('object_id')
            ->map(fn ($rows) => $rows->groupBy('taxonomy')
                ->map(fn ($r) => $r->mapWithKeys(fn ($row) => [
                    $row->term_id => html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5),
                ])->all())
                ->all());
    }

    /** Turns rows from query() into Astro Recipe objects. */
    private function present(Collection $posts): array
    {
        if($posts->isEmpty())
        {
            return [];
        }
        else
        {
            $ids = $posts->pluck('ID')->all();
            $meta = $this->meta($ids);
            $terms = $this->terms($ids);
            $images = $this->images($meta->map(fn ($m) => (int) ($m['_thumbnail_id'] ?? 0))->filter()->all());
            $views = $this->views($ids);

            return $posts->map(function($post) use ($meta, $terms, $images, $views) {
                $post_meta = $meta->get($post->ID, []);
                $post_terms = $terms->get($post->ID, []);
                return [
                    'id' => (integer) $post->ID,
                    'slug' => $post->post_name,
                    'title' => html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5),
                    'image' => $images[(int) ($post_meta['_thumbnail_id'] ?? 0)] ?? '',
                    'category' => '',
                    'time' => '',
                    // 'time' => $this->minutes($m['recipe_prep_time'] ?? '', $m['recipe_prep_time_text'] ?? '')
                    //     + $this->minutes($m['recipe_cook_time'] ?? '', $m['recipe_cook_time_text'] ?? '')
                    //     + $this->minutes($m['recipe_passive_time'] ?? '', $m['recipe_passive_time_text'] ?? ''),
                    'isNew' => strtotime($post->post_date) > strtotime('-30 days'),
                    'isTop' => '',
                    'rating' => (float) ($post_meta['recipe_rating'] ?? 0),
                    'ingredients' => [],
                    'comments' => (int) $post->comment_count,
                    'views' => $views[$post->ID] ?? 0,
                    'likes' => '',
                ];

            })->values()->all();
        }
    }

    private function views(array $ids): array
    {
        return $this->db->table('post_views_realtime')
        ->whereIn('post_id', $ids)
        ->where('view_type', 'normal')
        ->where('output_type', 'content')
        ->pluck('post_views_total', 'post_id')
        ->all();
    }

//
//     /** Latest published recipes, newest first. */
//     public function getNewRecipes(int $limit = 10, string $lang = 'ru'): array
//     {
//         $posts = $this->query($lang)
//             ->orderByDesc('p.post_date')
//             ->limit($limit)
//             ->get($this->columns());
//
//         return $this->present($posts);
//     }
//
//     /** Newest recipes of one category (course term id, e.g. 257 = "Вторые блюда"). */
//     public function getRecipesByCategoryId(int $categoryId, int $limit = 12, int $page = 1, string $lang = 'ru'): array
//     {
//         $posts = $this->query($lang)
//             ->whereExists(fn ($q) => $q->selectRaw('1')
//                 ->from('term_relationships as r')
//                 ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
//                 ->whereColumn('r.object_id', 'p.ID')
//                 ->where('tt.taxonomy', 'course')
//                 ->where('tt.term_id', $categoryId))
//             ->orderByDesc('p.post_date')
//             ->forPage($page, $limit)
//             ->get($this->columns());
//
//         return $this->present($posts);
//     }
//
//     /** Published recipes of one WPML language, with their like count joined as "likes". */
//     private function query(string $lang): Builder
//     {
//         return $this->db->table('posts as p')
//             ->leftJoin('postmeta as likes', function ($join) {
//                 $join->on('likes.post_id', '=', 'p.ID')->where('likes.meta_key', 'like_post');
//             })
//             ->join('icl_translations as tr', function ($join) use ($lang) {
//                 $join->on('tr.element_id', '=', 'p.ID')
//                     ->where('tr.element_type', 'post_recipe')
//                     ->where('tr.language_code', $lang);
//             })
//             ->where('p.post_type', 'recipe')
//             ->where('p.post_status', 'publish');
//     }
//
//     private function columns(): array
//     {
//         return ['p.ID', 'p.post_name', 'p.post_title', 'p.post_date', 'p.comment_count', 'likes.meta_value as likes'];
//     }
//
//
//     /** "20" + "минут" → 20, "1,5" + "часа" → 90, "1 час" + "" → 60 */
//     private function minutes(string $value, string $unit): int
//     {
//         if (! preg_match('/(\d+(?:[.,]\d+)?)/u', $value, $m)) {
//             return 0;
//         }
//         $number = (float) str_replace(',', '.', $m[1]);
//         $text = mb_strtolower($value.' '.$unit);
//
//         return (int) round(match (true) {
//             str_contains($text, 'час') => $number * 60,
//             (bool) preg_match('/\bд(ень|ня|ней)/u', $text) => $number * 1440,
//             default => $number,
//         });
//     }
}
