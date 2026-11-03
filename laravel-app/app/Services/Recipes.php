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

    /** Most viewed recipes (post_views_realtime). */
    public function getTopRecipes(int $limit = 10): array
    {
        $posts = $this->db->table('posts as p')
        ->leftJoin('post_views_realtime as v', function ($join) {
            $join->on('v.post_id', '=', 'p.ID')
            ->where('v.view_type', 'normal')
            ->where('v.output_type', 'content');
        })
        ->where('p.post_type', 'recipe')
        ->where('p.post_status', 'publish')
        ->orderByDesc('v.post_views_total')
        ->limit($limit)
        ->get(['p.*']);

        return $this->present($posts);
    }

    //     /** Latest published recipes, newest first. */
    public function getNewRecipes(int $limit = 10): array
    {
        $posts = $this->db->table('posts as p')
        ->leftJoin('post_views_realtime as v', function ($join) {
            $join->on('v.post_id', '=', 'p.ID')
            ->where('v.view_type', 'normal')
            ->where('v.output_type', 'content');
        })
        ->where('p.post_type', 'recipe')
        ->where('p.post_status', 'publish')
        ->orderByDesc('p.post_date')
        ->limit($limit)
        ->get(['p.*']);

        return $this->present($posts);
    }

    /** attachment_id => image URL */
    private function images(array $attachmentIds): array
    {
        return app(Attachments::class)->urls($attachmentIds);
    }

    /** post_id => [meta_key => value] */
    private function meta(array $ids): Collection
    {
        return $this->db->table('postmeta')
            ->whereIn('post_id', $ids)
            ->whereIn('meta_key', [
                '_thumbnail_id', 'recipe_rating', '_yoast_wpseo_primary_course', 'pld_like_count', 'pld_dislike_count', 'type', 'recipe_servings', 'complexity', 'calorific_value', 'recipe_notes',
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

    /**
     * One page of a recipe query: {recipes, total, page, pages}.
     * The query must select one row per recipe (no duplicates from joins).
     */
    public function paginate(Builder $query, int $page = 1, int $perPage = 24): array
    {
        $total = (clone $query)->count('p.ID');
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);

        return [
            // Ties (equal or missing views) need a fixed order, or a recipe could show up on two pages.
            'recipes' => $this->present($query->orderBy('p.ID')->forPage($page, $perPage)->get(['p.*'])),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /** Turns rows from query() into Astro Recipe objects. */
    public function present(Collection $posts): array
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
            $categories = $this->categories($ids);
            $ratings = app(Comments::class)->ratings($ids);

            return $posts->map(function($post) use ($meta, $terms, $images, $views, $categories, $ratings) {
                $post_meta = $meta->get($post->ID, []);
                $post_terms = $terms->get($post->ID, []);
                return [
                    'id' => (integer) $post->ID,
                    'slug' => $post->post_name,
                    'title' => html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5),
                    'image' => $images[(int) ($post_meta['_thumbnail_id'] ?? 0)] ?? '',
                    'categories' => [
                        'course' => $categories[$post->ID]['course'] ?? [],
                        'country' => $categories[$post->ID]['cuisine'] ?? [],
                    ],
                    'time' => $this->totalTime($post_meta),
                    'minutes' => $this->totalMinutes($post_meta),
                    'isNew' => strtotime($post->post_date) > strtotime('-30 days'),
                    // ACF checkbox "type" on the recipe, stored as a:1:{i:0;s:3:"top";}
                    'isTop' => in_array('top', (array) @unserialize($post_meta['type'] ?? '', ['allowed_classes' => false]), true),
                    'servings' => (int) ($post_meta['recipe_servings'] ?? 0),
                    // Average of the stars in approved reviews; recipes nobody has rated yet keep
                    // the rating set in WP Ultimate Recipe, so the catalogue isn't left without stars.
                    'rating' => $ratings[$post->ID]['average'] ?? (float) ($post_meta['recipe_rating'] ?? 0),
                    'ratingCount' => $ratings[$post->ID]['count'] ?? 0,
                    'ingredients' => [],
                    'comments' => (int) $post->comment_count,
                    'views' => [
                        'count' => (int) ($views[$post->ID] ?? 0),
                        'short' => $this->shortNumber((int) ($views[$post->ID] ?? 0)),
                    ],
                    'likes' => [
                        'count' => $this->netLikes($post_meta),
                        'short' => $this->shortNumber($this->netLikes($post_meta)),
                    ],
                ];

            })->values()->all();
        }
    }

    /**
     * Recipe categories and countries:
     * post_id => ['course' => [257 => 'Вторые блюда'], 'cuisine' => [329 => 'Италия']]
     */
    private function categories(array $ids): array
    {
        return $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->whereIn('r.object_id', $ids)
            ->whereIn('tt.taxonomy', ['course', 'cuisine'])
            ->orderBy('r.term_order')
            ->get(['r.object_id', 'tt.taxonomy', 't.term_id', 't.name'])
            ->groupBy(['object_id', 'taxonomy'])
            ->map(fn ($byTaxonomy) => $byTaxonomy->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [
                (int) $row->term_id => html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5),
            ])->all())->all())
            ->all();
    }

    /**
     * Likes minus dislikes (Posts Like Dislike). Dislikes are stored as positive numbers;
     * abs() keeps the result right if some were written with a minus.
     */
    public static function netLikes(array $meta): int
    {
        return (int) ($meta['pld_like_count'] ?? 0) - abs((int) ($meta['pld_dislike_count'] ?? 0));
    }

    /** Dish types and countries of a recipe with slugs for /category/{slug}: {course: [{name, slug}], country: [...]}. */
    private function categoryLinks(int $postId): array
    {
        $rows = $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('r.object_id', $postId)
            ->whereIn('tt.taxonomy', ['course', 'cuisine'])
            ->orderBy('r.term_order')
            ->get(['tt.taxonomy', 't.name', 't.slug']);
        $links = fn (string $taxonomy) => $rows->where('taxonomy', $taxonomy)
            ->map(fn ($row) => ['name' => html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5), 'slug' => $row->slug])
            ->values()
            ->all();

        return ['course' => $links('course'), 'country' => $links('cuisine')];
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

    /** 950 → "950", 3512 → "3.5K", 107726 → "107.7K", 1250000 → "1.3M" */
    private function shortNumber(int $n): string
    {
        // Likes minus dislikes can be negative: shorten the size, keep the sign.
        $sign = $n < 0 ? '-' : '';
        $n = abs($n);
        foreach (['M' => 1_000_000, 'K' => 1_000] as $suffix => $size) {
            if ($n >= $size * 0.99995) { // 999 950 already rounds to 1M, not 1000K
                return $sign.round($n / $size, 1).$suffix;
            }
        }

        return $sign.$n;
    }

    /** Prep + cook + passive time as text: "15 минут", "1 час 40 минут", "2 дня 3 часа". */
    /** Prep + cook + passive time in minutes. */
    private function totalMinutes(array $meta): int
    {
        $total = 0;
        foreach (['prep', 'cook', 'passive'] as $type) {
            $total += $this->minutes($meta["recipe_{$type}_time"] ?? '', $meta["recipe_{$type}_time_text"] ?? '');
        }

        return $total;
    }

    private function totalTime(array $meta): string
    {
        return $this->formatMinutes($this->totalMinutes($meta));
    }

    /** 100 → "1 ч 40 мин", 0 → "" */
    private function formatMinutes(int $total): string
    {
        $days = intdiv($total, 1440);
        $hours = intdiv($total % 1440, 60);
        $minutes = $total % 60;

        return implode(' ', array_filter([
            $days ? $days.' '.$this->plural($days, 'д', 'д', 'д') : '',
            $hours ? $hours.' '.$this->plural($hours, 'ч', 'ч', 'ч') : '',
            $minutes ? $minutes.' '.$this->plural($minutes, 'мин', 'мин', 'мин') : '',
        ]));
    }

    /** Russian plural form: 1 минута, 2 минуты, 5 минут, 21 минута. */
    private function plural(int $n, string $one, string $few, string $many): string
    {
        $n %= 100;
        if ($n >= 11 && $n <= 19) {
            return $many;
        }

        return match ($n % 10) {
            1 => $one,
            2, 3, 4 => $few,
            default => $many,
        };
    }

    /** "20" + "минут" → 20, "1,5" + "часа" → 90, "1 час" + "" → 60 */
    private function minutes(string $value, string $unit): int
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)/u', $value, $meta)) {
            return 0;
        }
        $number = (float) str_replace(',', '.', $meta[1]);
        $text = mb_strtolower($value.' '.$unit);

        return (int) round(match (true) {
            str_contains($text, 'час') => $number * 60,
            (bool) preg_match('/\bд(ень|ня|ней)/u', $text) => $number * 1440,
            default => $number,
        });
    }

    /** One published Russian recipe by slug, or null. */
    /**
     * Every published Russian recipe for the site search: {slug, title, time, course, ingredients}.
     * Lighter than present(): no images, views or likes.
     */
    public function getSearchIndex(): array
    {
        $posts = $this->db->table('posts as p')
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            ->orderBy('p.post_title')
            ->get(['p.ID', 'p.post_name', 'p.post_title']);
        $ids = $posts->pluck('ID')->all();
        $meta = $this->meta($ids);
        // post_id => [taxonomy => [names]]
        $terms = $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->whereIn('r.object_id', $ids)
            ->whereIn('tt.taxonomy', ['course', 'ingredient'])
            ->get(['r.object_id', 'tt.taxonomy', 't.name'])
            ->groupBy(['object_id', 'taxonomy']);
        $names = fn ($rows) => collect($rows)->map(fn ($row) => html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5))->unique()->values()->all();

        return $posts->map(fn ($post) => [
            'slug' => $post->post_name,
            'title' => html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5),
            'time' => $this->totalTime($meta->get($post->ID, [])),
            'course' => $names($terms[$post->ID]['course'] ?? [])[0] ?? '',
            'ingredients' => $names($terms[$post->ID]['ingredient'] ?? []),
        ])->all();
    }

    /** Slugs of all published Russian recipes, for building the recipe pages. */
    public function getSlugs(): array
    {
        return $this->db->table('posts as p')
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            ->orderBy('p.ID')
            ->pluck('p.post_name')
            ->all();
    }

    /** Published recipes by id, in the order of $ids (a visitor's favourites). */
    public function getByIds(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $order = array_flip($ids);
        $posts = $this->publishedQuery()->whereIn('p.ID', $ids)->get(['p.*'])->sortBy(fn ($post) => $order[$post->ID]);

        return $this->present($posts->values());
    }

    /** Recipes of the authors published after $sinceGmt ("Y-m-d H:i:s"), newest first. */
    public function getByAuthorsSince(array $authorIds, string $sinceGmt, int $limit = 24): array
    {
        if (! $authorIds) {
            return [];
        }

        return $this->present($this->publishedQuery()
            ->whereIn('p.post_author', $authorIds)
            ->where('p.post_date_gmt', '>', $sinceGmt)
            ->orderByDesc('p.post_date_gmt')
            ->limit($limit)
            ->get(['p.*']));
    }

    /** Published Russian recipes, without a select. */
    private function publishedQuery(): Builder
    {
        return $this->db->table('posts as p')
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish');
    }

    /** Post id of a published Russian recipe, or null. */
    public function getRecipeId(string $slug): ?int
    {
        $id = $this->db->table('posts as p')
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            ->where('p.post_name', $slug)
            ->value('p.ID');

        return $id ? (int) $id : null;
    }

    public function getRecipeBySlug(string $slug): ?array
    {
        $post = $this->db->table('posts as p')
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            ->where('p.post_name', $slug)
            ->first(['p.*']);

        if (! $post) {
            return null;
        }
        $recipe = $this->present(collect([$post]))[0];
        $recipe['ingredients'] = $this->ingredients($post->ID);
        $recipe['reviews'] = app(Comments::class)->getForPost($post->ID);
        $recipe['votes'] = app(Likes::class)->get($post->ID);
        $recipe['seoText'] = app(SeoText::class)->forPost($post->ID);
        $recipe['categoryLinks'] = $this->categoryLinks($post->ID);
        $recipe['seo'] = app(Seo::class)->forPost('recipe', $post->post_name);

        // Quick facts: an empty value means "not filled in WordPress", and the page hides it.
        $meta = $this->meta([$post->ID])->get($post->ID, []);
        $recipe['prepTime'] = $this->formatMinutes($this->minutes($meta['recipe_prep_time'] ?? '', $meta['recipe_prep_time_text'] ?? ''));
        $recipe['cookTime'] = $this->formatMinutes($this->minutes($meta['recipe_cook_time'] ?? '', $meta['recipe_cook_time_text'] ?? ''));
        $recipe['complexity'] = trim($meta['complexity'] ?? '');
        $recipe['calories'] = is_numeric($meta['calorific_value'] ?? null) && $meta['calorific_value'] > 0 ? (int) $meta['calorific_value'] : null;
        $recipe['steps'] = $this->steps($post->ID);
        // "Совет от шефа": HTML from WordPress, null when empty.
        $notes = trim($meta['recipe_notes'] ?? '');
        $recipe['tip'] = trim(strip_tags($notes)) !== '' ? $notes : null;
        $recipe['similar'] = $this->getSimilarRecipes($post->ID);
        $recipe['author'] = $this->author((int) $post->post_author);
        // The history is a Carbon Fields field; older posts may have the block in their content instead.
        $history = trim((string) $this->db->table('postmeta')
            ->where('post_id', $post->ID)
            ->where('meta_key', '_crb_recipe_history')
            ->value('meta_value'));
        $recipe['history'] = $history !== '' ? $history : $this->history($post->post_content);

        return $recipe;
    }

    /**
     * The most used ingredients and their most viewed recipes, $limit recipes in all:
     * 5 ingredients × 4 recipes for limit=20. Each recipe gets "groups": the names of
     * those ingredients it contains, so the carousel can filter by them.
     */
    public function getRecipesByIngredients(int $ingredients = 5, int $limit = 20): array
    {
        $terms = $this->db->table('term_taxonomy as tt')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'ingredient')
            ->where('tt.count', '>', 0)
            ->orderByDesc('tt.count')
            ->limit($ingredients)
            ->get(['tt.term_taxonomy_id', 't.name']);

        $perIngredient = (int) ceil($limit / max(1, $terms->count()));
        $posts = collect();
        $groups = [];
        foreach ($terms as $term) {
            $name = html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5);
            $found = $this->db->table('posts as p')
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
                ->whereNotIn('p.ID', $posts->pluck('ID')) // a recipe shows up once
                ->orderByDesc('v.post_views_total')
                ->limit($perIngredient)
                ->get(['p.*']);
            foreach ($found as $post) {
                $groups[$post->ID] = [$name];
            }
            $posts = $posts->concat($found);
        }

        return collect($this->present($posts->take($limit)))
            ->map(fn ($recipe) => $recipe + ['groups' => $groups[$recipe['id']] ?? []])
            ->all();
    }

    /** Recipes marked as "top" in WordPress, newest first. */
    public function getFeaturedRecipes(int $limit = 10): array
    {
        $posts = $this->db->table('posts as p')
            ->join('postmeta as type', function ($join) {
                $join->on('type.post_id', '=', 'p.ID')
                    ->where('type.meta_key', 'type')
                    ->where('type.meta_value', 'like', '%"top"%');
            })
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            ->orderByDesc('p.post_date')
            ->limit($limit)
            ->get(['p.*']);

        return $this->present($posts);
    }

    /**
     * Recipes sharing the most ingredients with this one; ties go to the most viewed.
     */
    public function getSimilarRecipes(int $postId, int $limit = 10): array
    {
        $ingredients = $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->where('r.object_id', $postId)
            ->where('tt.taxonomy', 'ingredient')
            ->pluck('r.term_taxonomy_id');
        if ($ingredients->isEmpty()) {
            return [];
        }

        $shared = $this->db->table('term_relationships as r')
            ->join('posts as p', 'p.ID', '=', 'r.object_id')
            ->leftJoin('post_views_realtime as v', function ($join) {
                $join->on('v.post_id', '=', 'p.ID')
                    ->where('v.view_type', 'normal')
                    ->where('v.output_type', 'content');
            })
            ->whereIn('r.term_taxonomy_id', $ingredients)
            ->where('p.ID', '!=', $postId)
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            ->groupBy('p.ID', 'v.post_views_total')
            ->orderByRaw('COUNT(*) DESC')
            ->orderByDesc('v.post_views_total')
            ->limit($limit)
            ->pluck('p.ID');

        // Keep the order of $shared: whereIn returns rows in any order.
        $posts = $this->db->table('posts')->whereIn('ID', $shared)->get()->sortBy(fn ($post) => $shared->search($post->ID));

        return $this->present($posts->values());
    }

    /** The recipe's WordPress author (posts.post_author): {id, slug, name, avatar, recipes}. */
    private function author(int $userId): ?array
    {
        $slug = $this->db->table('users')->where('ID', $userId)->value('user_nicename');

        return collect(app(Authors::class)->getAll())->firstWhere('slug', $slug);
    }

    /** Cooking steps of one recipe (WP Ultimate Recipe "recipe_instructions"): [{text, group}]. */
    private function steps(int $postId): array
    {
        $value = $this->db->table('postmeta')
            ->where('post_id', $postId)
            ->where('meta_key', 'recipe_instructions')
            ->value('meta_value');
        $items = @unserialize((string) $value, ['allowed_classes' => false]);
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(fn ($item) => [
                'text' => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($item['description'] ?? ''), ENT_QUOTES | ENT_HTML5))),
                'group' => trim((string) ($item['group'] ?? '')),
            ])
            ->filter(fn ($step) => $step['text'] !== '')
            ->values()
            ->all();
    }

    /**
     * HTML of the "История происхождения" block (see wp-content/mu-plugins/foodies-origin-story.php)
     * from the recipe content, or null when the recipe has none. The block is a wp:group with
     * nested groups, so its end is found by counting group openings and closings.
     */
    private function history(string $content): ?string
    {
        $start = strpos($content, '<!-- wp:group {"className":"origin-story"} -->');
        if ($start === false) {
            return null;
        }
        preg_match_all('/<!-- (\/?)wp:group\b/', $content, $tags, PREG_OFFSET_CAPTURE, $start);
        $depth = 0;
        foreach ($tags[1] as [$slash, $offset]) {
            $depth += $slash === '/' ? -1 : 1;
            if ($depth === 0) {
                $end = strpos($content, '-->', $offset) + 3;
                // Block comments are editor markup only.
                return trim(preg_replace('/<!--.*?-->/s', '', substr($content, $start, $end - $start)));
            }
        }

        return null;
    }

    /**
     * Ingredients of one recipe (WP Ultimate Recipe "recipe_ingredients").
     * amount is the text as written ("1/2", "по вкусу"); quantity is the same as a number
     * for rescaling to other servings, or null when it isn't a number.
     */
    private function ingredients(int $postId): array
    {
        $value = $this->db->table('postmeta')
            ->where('post_id', $postId)
            ->where('meta_key', 'recipe_ingredients')
            ->value('meta_value');
        $items = @unserialize((string) $value, ['allowed_classes' => false]);
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->filter(fn ($item) => is_array($item) && trim($item['ingredient'] ?? '') !== '')
            ->map(fn ($item) => [
                'name' => trim(html_entity_decode($item['ingredient'], ENT_QUOTES | ENT_HTML5)),
                'amount' => trim((string) ($item['amount'] ?? '')),
                'quantity' => is_numeric($item['amount_normalized'] ?? null) && $item['amount_normalized'] > 0
                    ? (float) $item['amount_normalized']
                    : null,
                'unit' => trim((string) ($item['unit'] ?? '')),
                'notes' => trim((string) ($item['notes'] ?? '')),
                'group' => trim((string) ($item['group'] ?? '')),
            ])
            ->values()
            ->all();
    }
}
