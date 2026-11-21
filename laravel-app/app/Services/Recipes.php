<?php

namespace App\Services;

use App\Support\Lang;
use App\Support\Units;
use App\Support\WpHtml;
use App\Support\YouTubeUrl;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recipes from the foodies.academy WordPress database.
 * Every method returns arrays that match the Recipe type in astro-app/src/types/recipe.ts.
 */
class Recipes
{
    private ConnectionInterface $db;

    private Translations $translations;

    public function __construct()
    {
        $this->db = DB::connection('site');
        $this->translations = app(Translations::class);
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
            ->tap(fn ($q) => $this->translations->scope($q))
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
            ->tap(fn ($q) => $this->translations->scope($q))
            ->orderByDesc('p.post_date')
            ->limit($limit)
            ->get(['p.*']);

        return $this->present($posts);
    }

    /**
     * Attachment ID of the recipe's cover: the featured image, or the first photo of the "gallery"
     * field, which is what the old theme showed (the live database has almost no featured images).
     */
    private function coverId(array $meta): int
    {
        $gallery = @unserialize($meta['gallery'] ?? '', ['allowed_classes' => false]);

        return (int) ($meta['_thumbnail_id'] ?? 0) ?: (int) (is_array($gallery) ? reset($gallery) : 0);
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
                'recipe_description', 'gallery', 'recipe_video_embed', 'video', '_yoast_wpseo_focuskw',
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
        if ($posts->isEmpty()) {
            return [];
        } else {
            $ids = $posts->pluck('ID')->all();
            $meta = $this->meta($ids);
            $terms = $this->terms($ids);
            $covers = $meta->map(fn ($m) => $this->coverId($m));
            $images = $this->images($covers->filter()->all());
            $views = app(PostViews::class)->totals($ids);
            $categories = $this->categories($ids);
            // Another language: the translation's title, address and reviews; the rest is the original's.
            $translated = $this->translations->posts($ids);
            $own = fn ($id) => (int) ($translated[$id]->ID ?? $id);
            $reviewIds = array_map($own, $ids);
            $ratings = app(Comments::class)->ratings($reviewIds);
            $commentCounts = app(Comments::class)->counts($reviewIds);
            $ownMeta = $translated ? $this->meta($reviewIds) : collect();

            return $posts->map(function ($post) use ($meta, $terms, $images, $covers, $views, $categories, $ratings, $commentCounts, $translated, $own, $ownMeta) {
                $post_meta = $meta->get($post->ID, []);
                $post_terms = $terms->get($post->ID, []);
                $text = $translated[$post->ID] ?? $post;
                $id = $own($post->ID);

                return [
                    // The original's id in every language: favourites are kept by it.
                    'id' => (int) $post->ID,
                    'slug' => $text->post_name,
                    'title' => html_entity_decode($text->post_title, ENT_QUOTES | ENT_HTML5),
                    'image' => $images[$covers->get($post->ID, 0)] ?? '',
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
                    // A translation has its own reviews; no stars of the original's.
                    'rating' => $ratings[$id]['average'] ?? (float) ($translated ? ($ownMeta->get($id, [])['recipe_rating'] ?? 0) : ($post_meta['recipe_rating'] ?? 0)),
                    'ratingCount' => $ratings[$id]['count'] ?? 0,
                    'ingredients' => [],
                    // Video recipe, null without one. recipe_video_embed (WP Ultimate Recipe) or, in
                    // databases that never moved it there, the old theme's ACF field "video".
                    // A translation's own video, else the original's.
                    'youtubeId' => YouTubeUrl::id(($ownMeta->get($id, [])['recipe_video_embed'] ?? '') ?: ($post_meta['recipe_video_embed'] ?? '') ?: ($post_meta['video'] ?? '')),
                    'comments' => $commentCounts[$id] ?? 0,
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
        $rows = $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->whereIn('r.object_id', $ids)
            ->whereIn('tt.taxonomy', ['course', 'cuisine'])
            ->orderBy('r.term_order')
            ->get(['r.object_id', 'tt.taxonomy', 't.term_id', 't.name']);

        return $this->translateTerms($rows)
            ->groupBy(['object_id', 'taxonomy'])
            ->map(fn ($byTaxonomy) => $byTaxonomy->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [
                (int) $row->term_id => html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5),
            ])->all())->all())
            ->all();
    }

    /**
     * Rows of terms ({term_id, name, slug?}) in the current language: the translation's name and
     * slug; in another language a term without a translation is left out.
     */
    private function translateTerms(Collection $rows): Collection
    {
        if (Lang::isDefault()) {
            return $rows;
        }
        $terms = $this->translations->terms($rows->pluck('term_id')->map(fn ($id) => (int) $id)->unique()->all());

        return $rows->filter(fn ($row) => isset($terms[(int) $row->term_id]))
            ->map(fn ($row) => (object) (['name' => $terms[(int) $row->term_id]->name, 'slug' => $terms[(int) $row->term_id]->slug] + (array) $row))
            ->values();
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
            ->get(['tt.taxonomy', 't.term_id', 't.name', 't.slug']);
        $rows = $this->translateTerms($rows);
        $links = fn (string $taxonomy) => $rows->where('taxonomy', $taxonomy)
            ->map(fn ($row) => ['name' => html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5), 'slug' => $row->slug])
            ->values()
            ->all();

        return ['course' => $links('course'), 'country' => $links('cuisine')];
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
            $days ? $days.' '.Lang::text('д', 'd') : '',
            $hours ? $hours.' '.Lang::text('ч', 'h') : '',
            $minutes ? $minutes.' '.Lang::text('мин', 'min') : '',
        ]));
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
            ->tap(fn ($q) => $this->translations->scope($q))
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
            ->get(['r.object_id', 'tt.taxonomy', 't.term_id', 't.name'])
            ->groupBy(['object_id', 'taxonomy']);
        $names = fn ($rows) => collect($rows)->map(fn ($row) => html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5))->unique()->values()->all();
        if (! Lang::isDefault()) {
            // Names of the translated terms; the course and ingredients of the original.
            $translatedTerms = $this->translations->terms($terms->flatten()->pluck('term_id')->map(fn ($id) => (int) $id)->unique()->all());
            $names = fn ($rows) => collect($rows)->map(fn ($row) => $translatedTerms[(int) $row->term_id]->name ?? null)->filter()->unique()->values()->all();
        }
        $translated = $this->translations->posts($ids);

        return $posts->map(fn ($post) => [
            'slug' => ($translated[$post->ID] ?? $post)->post_name,
            'title' => html_entity_decode(($translated[$post->ID] ?? $post)->post_title, ENT_QUOTES | ENT_HTML5),
            'time' => $this->totalTime($meta->get($post->ID, [])),
            'course' => $names($terms[$post->ID]['course'] ?? [])[0] ?? '',
            'ingredients' => $names($terms[$post->ID]['ingredient'] ?? []),
        ])->all();
    }

    /** Slugs of all published Russian recipes, for building the recipe pages. */
    public function getSlugs(): array
    {
        $ids = $this->db->table('posts as p')
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            ->tap(fn ($q) => $this->translations->scope($q))
            ->orderBy('p.ID')
            ->pluck('p.post_name', 'p.ID');
        if (Lang::isDefault()) {
            return $ids->values()->all();
        }
        $translated = $this->translations->posts($ids->keys()->all());

        return $ids->keys()->map(fn ($id) => $translated[$id]->post_name)->values()->all();
    }

    /**
     * Every published recipe for the sitemaps: its address, last change and photos in full size
     * (the cover, then the rest of the gallery).
     */
    public function getSitemap(): array
    {
        $posts = $this->db->table('posts as p')
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            ->tap(fn ($q) => $this->translations->scope($q))
            ->orderBy('p.ID')
            ->get(['p.ID', 'p.post_name', 'p.post_title', 'p.post_modified_gmt']);
        $translated = $this->translations->posts($posts->pluck('ID')->all());
        $meta = $this->db->table('postmeta')
            ->whereIn('post_id', $posts->pluck('ID'))
            ->whereIn('meta_key', ['_thumbnail_id', 'gallery'])
            ->get(['post_id', 'meta_key', 'meta_value'])
            ->groupBy('post_id')
            ->map(fn ($rows) => $rows->pluck('meta_value', 'meta_key')->all());

        $photoIds = $meta->map(function ($m) {
            $gallery = @unserialize($m['gallery'] ?? '', ['allowed_classes' => false]);

            return array_values(array_unique(array_filter([$this->coverId($m), ...(is_array($gallery) ? array_map('intval', $gallery) : [])])));
        });
        $urls = app(Attachments::class)->urls($photoIds->flatten()->unique()->all(), original: true);

        return $posts->map(fn ($post) => [
            'slug' => ($translated[$post->ID] ?? $post)->post_name,
            'title' => html_entity_decode(($translated[$post->ID] ?? $post)->post_title, ENT_QUOTES | ENT_HTML5),
            // Old WordPress rows may have no date (0000-00-00).
            'modified' => str_starts_with(($translated[$post->ID] ?? $post)->post_modified_gmt, '0000') ? null : Carbon::parse(($translated[$post->ID] ?? $post)->post_modified_gmt, 'UTC')->toIso8601String(),
            'images' => array_values(array_filter(array_map(fn ($id) => $urls[$id] ?? '', $photoIds->get($post->ID, [])))),
        ])->all();
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
            ->where('p.post_status', 'publish')
            ->tap(fn ($q) => $this->translations->scope($q));
    }

    /**
     * Post id of the published recipe with $slug in the request's language (a Russian slug finds
     * any recipe), or null: reviews go to the page's own post, likes and views to all translations.
     */
    public function getRecipeId(string $slug): ?int
    {
        // ?lang=en: the translation, which may share the original's slug (Translations::publicSlug()).
        if (! Lang::isDefault()) {
            return $this->translations->find('recipe', $slug)['id'] ?? null;
        }
        $id = $this->db->table('posts as p')
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            ->where('p.post_name', $slug)
            ->value('p.ID');

        return $id ? (int) $id : null;
    }

    /** Id of the original of a published recipe in any language (what favourites keep), or null. */
    public function getOriginalId(string $slug): ?int
    {
        $id = $this->getRecipeId($slug);

        return $id ? $this->translations->group($id)[Lang::DEFAULT] ?? null : null;
    }

    public function getRecipeBySlug(string $slug): ?array
    {
        // Another language: the slug is the translation's, the recipe is built from the original.
        $found = Lang::isDefault() ? null : $this->translations->find('recipe', $slug);
        $post = $this->db->table('posts as p')
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            ->tap(fn ($q) => $this->translations->scope($q))
            ->when($found, fn ($q) => $q->where('p.ID', $found['source']), fn ($q) => $q->where('p.post_name', $slug))
            ->first(['p.*']);

        if (! $post || (! Lang::isDefault() && ! $found)) {
            return null;
        }
        $recipe = $this->original($post);

        return $found ? $this->translate($recipe, $post, $found['id']) : $recipe;
    }

    /** The full recipe of a Russian post. */
    private function original(object $post): array
    {
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
        // Saved as HTML or, by WP Ultimate Recipe's editor, as plain lines: both become paragraphs.
        $recipe['tip'] = WpHtml::render($notes) ?: null;
        // Text under the title, "" when empty.
        $recipe['description'] = trim(html_entity_decode(strip_tags($meta['recipe_description'] ?? ''), ENT_QUOTES | ENT_HTML5));
        $recipe['gallery'] = $this->gallery($meta, $recipe['image']);
        // For the page's structured data: the video's upload date is the recipe's last change,
        // and the Yoast focus keyword goes to "keywords" ("" when not set).
        $recipe['updatedAt'] = Carbon::parse(str_starts_with($post->post_modified_gmt, '0000') ? $post->post_date_gmt : $post->post_modified_gmt, 'UTC')->toIso8601String();
        $recipe['focusKeyword'] = trim(html_entity_decode($meta['_yoast_wpseo_focuskw'] ?? '', ENT_QUOTES | ENT_HTML5));
        $recipe['similar'] = $this->getSimilarRecipes($post->ID);
        $recipe['author'] = $this->author((int) $post->post_author);
        // The history: the Carbon Fields field, or the old theme's ACF field "history" (the same text in
        // databases that never moved it); older posts may have the block in their content instead.
        $historyMeta = $this->db->table('postmeta')
            ->where('post_id', $post->ID)
            ->whereIn('meta_key', ['_crb_recipe_history', 'history'])
            ->pluck('meta_value', 'meta_key');
        $history = trim((string) (trim($historyMeta['_crb_recipe_history'] ?? '') ?: ($historyMeta['history'] ?? '')));
        $recipe['history'] = $history !== '' ? WpHtml::render($history) : $this->history($post->post_content);
        $recipe['translations'] = $this->translations->slugs((int) $post->ID);

        return $recipe;
    }

    private function likeOriginalNotes(string $notes, string $original): string
    {
        return $notes !== '' && str_starts_with($original, '(') && ! str_starts_with($notes, '(') ? "($notes)" : $notes;
    }

    /** Words of amounts that aren't numbers, for a translation. */
    private const AMOUNT_WORDS = ['пару' => 'a couple', 'несколько' => 'a few', 'по вкусу' => 'to taste', 'по желанию' => 'optional'];

    private const COMPLEXITY = ['Очень легко' => 'Very easy', 'Легко' => 'Easy', 'Средне' => 'Medium', 'Сложно' => 'Hard'];

    /**
     * The original's recipe with the texts of its translation $id: ingredient names (amounts and
     * units stay the original's, the unit in English), steps, tip, description, history, its own
     * reviews and SEO. Photos, terms, times and counts are the original's.
     */
    private function translate(array $recipe, object $original, int $id): array
    {
        $post = $this->db->table('posts')->where('ID', $id)->first();
        $meta = $this->meta([$id])->get($id, []);

        $names = $this->ingredients($id);
        $sameList = count($names) === count($recipe['ingredients']);
        // An ingredient written as its term is named as the translated term, as on its page.
        $termIds = array_values(array_filter(array_column($recipe['ingredients'], 'termId')));
        $russianTerms = $this->db->table('terms')->whereIn('term_id', $termIds)->pluck('name', 'term_id')
            ->map(fn ($name) => mb_strtolower(html_entity_decode($name, ENT_QUOTES | ENT_HTML5)));
        $translatedTerms = $this->translations->terms($termIds);
        foreach ($recipe['ingredients'] as $k => $item) {
            $code = $item['unitCode'];
            $term = ($russianTerms[$item['termId']] ?? null) === mb_strtolower($item['name']) ? ($translatedTerms[$item['termId']] ?? null) : null;
            $recipe['ingredients'][$k] = [
                'name' => $term->name ?? ($sameList ? $names[$k]['name'] : $item['name']),
                'amount' => self::AMOUNT_WORDS[mb_strtolower($item['amount'])] ?? str_replace(',', '.', $item['amount']),
                'unit' => $code ? Units::label($code, $item['quantity']) : $item['unit'],
                // In parentheses when the original's are: "(голяшка, ребра)" → "(shin, ribs)".
                'notes' => $sameList ? $this->likeOriginalNotes($names[$k]['notes'], $item['notes']) : '',
                'group' => $sameList ? $names[$k]['group'] : $item['group'],
            ] + $item;
        }
        $recipe['steps'] = $this->steps($id);
        $recipe['reviews'] = app(Comments::class)->getForPost($id);
        $recipe['seoText'] = app(SeoText::class)->forPost($id);
        $recipe['seo'] = app(Seo::class)->forPost('recipe', $post->post_name);
        $recipe['tip'] = WpHtml::render(trim($meta['recipe_notes'] ?? '')) ?: null;
        $recipe['description'] = trim(html_entity_decode(strip_tags($meta['recipe_description'] ?? ''), ENT_QUOTES | ENT_HTML5));
        $recipe['complexity'] = self::COMPLEXITY[$recipe['complexity']] ?? '';
        $recipe['focusKeyword'] = trim(html_entity_decode($meta['_yoast_wpseo_focuskw'] ?? '', ENT_QUOTES | ENT_HTML5));
        $recipe['updatedAt'] = Carbon::parse(str_starts_with($post->post_modified_gmt, '0000') ? $post->post_date_gmt : $post->post_modified_gmt, 'UTC')->toIso8601String();
        $history = $this->db->table('postmeta')->where('post_id', $id)->whereIn('meta_key', ['_crb_recipe_history', 'history'])->pluck('meta_value', 'meta_key');
        $history = trim((string) (trim($history['_crb_recipe_history'] ?? '') ?: ($history['history'] ?? '')));
        $recipe['history'] = $history !== '' ? WpHtml::render($history) : $this->history($post->post_content);

        return $recipe;
    }

    /**
     * Photo URLs for the page's carousel: the cover first, then the rest of the
     * "gallery" field (serialized attachment IDs) in their WordPress order.
     */
    private function gallery(array $meta, string $cover): array
    {
        $ids = @unserialize($meta['gallery'] ?? '', ['allowed_classes' => false]);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        $urls = $this->images($ids);
        $photos = array_map(fn ($id) => $urls[$id] ?? '', $ids);

        return array_values(array_unique(array_filter([$cover, ...$photos])));
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

        // Another language: the ingredients' translated names; an untranslated one is left out.
        if (! Lang::isDefault()) {
            $translated = $this->translations->terms($this->db->table('term_taxonomy')->whereIn('term_taxonomy_id', $terms->pluck('term_taxonomy_id'))->pluck('term_id', 'term_taxonomy_id')->map(fn ($id) => (int) $id)->all());
            $termIds = $this->db->table('term_taxonomy')->whereIn('term_taxonomy_id', $terms->pluck('term_taxonomy_id'))->pluck('term_id', 'term_taxonomy_id');
            $terms = $terms->filter(fn ($term) => isset($translated[(int) $termIds[$term->term_taxonomy_id]]))
                ->map(fn ($term) => (object) ['term_taxonomy_id' => $term->term_taxonomy_id, 'name' => $translated[(int) $termIds[$term->term_taxonomy_id]]->name])
                ->values();
        }
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
                ->tap(fn ($q) => $this->translations->scope($q))
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
            ->tap(fn ($q) => $this->translations->scope($q))
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
            ->tap(fn ($q) => $this->translations->scope($q))
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
                // config/units.php: for converting to other units; null when unknown or empty.
                'unitCode' => Units::code((string) ($item['unit'] ?? '')) ?: null,
                'notes' => trim((string) ($item['notes'] ?? '')),
                'group' => trim((string) ($item['group'] ?? '')),
                // The ingredient term (the ingredient's page); 0 when none.
                'termId' => (int) ($item['ingredient_id'] ?? 0),
            ])
            ->values()
            ->all();
    }
}
