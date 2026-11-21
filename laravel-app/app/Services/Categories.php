<?php

namespace App\Services;

use App\Support\Lang;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Recipe categories from the foodies.academy WordPress database.
 * Types use the same names as Recipe::categories in the API: "course" and "country".
 */
class Categories
{
    /** API type => WordPress taxonomy */
    private const TAXONOMIES = [
        'course' => 'course',
        'country' => 'cuisine',
    ];

    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    /** Categories with the most recipes first. */
    public function getPopular(string $type, int $limit = 10): array
    {
        if (! Lang::isDefault()) {
            // Counts of translated recipes, not the term's own.
            return collect($this->getByType($type))->sortByDesc('count')->take($limit)->values()->all();
        }
        $terms = $this->query($type)
            ->where('tt.count', '>', 0)
            ->orderByDesc('tt.count')
            ->limit($limit)
            ->get($this->columns());

        return $this->present($terms, $type);
    }

    /** Categories whose name contains $name ("суп" → "Супы"), optionally of one type. */
    public function getByName(string $name, ?string $type = null): array
    {
        $types = $type ? [$type] : array_keys(self::TAXONOMIES);
        if (! Lang::isDefault()) {
            return collect($types)->flatMap(fn ($t) => array_filter($this->getByType($t), fn ($c) => mb_stripos($c['name'], $name) !== false))->values()->all();
        }

        return collect($types)
            ->flatMap(fn ($t) => $this->present(
                $this->query($t)->where('t.name', 'like', '%'.$name.'%')->orderBy('t.name')->get($this->columns()),
                $t,
            ))
            ->all();
    }

    /** Categories of one type, alphabetically; all of them when $limit is null. */
    public function getByType(string $type, ?int $limit = null): array
    {
        $terms = $this->query($type)
            ->orderBy('t.name')
            ->when($limit && Lang::isDefault(), fn ($query) => $query->limit($limit))
            ->get($this->columns());
        $categories = $this->present($terms, $type);
        if (! Lang::isDefault()) {
            $categories = collect($categories)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->when($limit, fn ($c) => $c->take($limit))->values()->all();
        }

        return $categories;
    }

    /** One category or country by slug, with one page of its recipes (most viewed first), or null. */
    public function getBySlug(string $slug, int $page = 1, int $perPage = 24): ?array
    {
        // Another language: the slug is the translated term's, the recipes are the original's.
        if (! Lang::isDefault()) {
            $slug = app(Translations::class)->termSource(self::TAXONOMIES, $slug) ?? '';
        }
        $term = $this->db->table('term_taxonomy as tt')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->whereIn('tt.taxonomy', self::TAXONOMIES)
            ->where('t.slug', $slug)
            ->first(['tt.term_taxonomy_id', 'tt.taxonomy', ...$this->columns()]);
        if (! $term) {
            return null;
        }

        $posts = $this->db->table('posts as p')
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
            ->tap(fn ($q) => app(Translations::class)->scope($q))
            ->orderByDesc('v.post_views_total');

        $type = array_search($term->taxonomy, self::TAXONOMIES, true);
        $category = $this->present(collect([$term]), $type)[0] ?? null;
        if (! $category) {
            return null;
        }

        return $category
            + ['seoText' => app(SeoText::class)->forTerm($category['ownId']), 'seo' => app(Seo::class)->forTerm($term->taxonomy, $category['wpSlug'])]
            + ['translations' => app(Translations::class)->termSlugs((int) $term->term_id)]
            + app(Recipes::class)->paginate($posts, $page, $perPage);
    }

    private function query(string $type): Builder
    {
        $taxonomy = self::TAXONOMIES[$type]
            ?? throw new InvalidArgumentException("Unknown category type \"$type\", expected: ".implode(', ', array_keys(self::TAXONOMIES)));

        return $this->db->table('term_taxonomy as tt')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.count', '>', 0)
            ->where('tt.taxonomy', $taxonomy)
            ->tap(fn ($q) => app(Translations::class)->scopeTerms($q));
    }

    /** term_taxonomy_id => published recipes of the current language (another language counts its translations). */
    private function counts(array $termTaxonomyIds): array
    {
        return $this->db->table('term_relationships as r')
            ->join('posts as p', fn ($j) => $j->on('p.ID', '=', 'r.object_id')->where('p.post_type', 'recipe')->where('p.post_status', 'publish'))
            ->whereIn('r.term_taxonomy_id', $termTaxonomyIds)
            ->tap(fn ($q) => app(Translations::class)->scope($q))
            ->groupBy('r.term_taxonomy_id')
            ->pluck(DB::raw('COUNT(*)'), 'r.term_taxonomy_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function columns(): array
    {
        return ['t.term_id', 't.name', 't.slug', 'tt.count', 'tt.term_taxonomy_id'];
    }

    /** term_id => [meta_key => meta_value] */
    private function meta(array $ids): Collection
    {
        return $this->db->table('termmeta')
            ->whereIn('term_id', $ids)
            ->get(['term_id', 'meta_key', 'meta_value'])
            ->groupBy('term_id')
            ->map(fn ($rows) => $rows->pluck('meta_value', 'meta_key')->all());
    }

    private function present(Collection $terms, string $type): array
    {
        if ($terms->isEmpty()) {
            return [];
        }
        // Another language: translated terms with translated recipes only, under their own name and slug.
        $translated = Lang::isDefault() ? [] : app(Translations::class)->terms($terms->pluck('term_id')->map(fn ($id) => (int) $id)->all());
        $counts = Lang::isDefault() ? [] : $this->counts($terms->pluck('term_taxonomy_id')->all());
        if (! Lang::isDefault()) {
            $terms = $terms->filter(fn ($t) => isset($translated[(int) $t->term_id]) && ($counts[$t->term_taxonomy_id] ?? 0) > 0)->values();
        }
        $meta = $this->meta($terms->pluck('term_id')->all());
        // An attachment id: the Carbon Fields image field, or the old theme's ACF field "image"
        // (the live database has only that one).
        $imageIds = $meta->map(fn ($m) => (int) ($m['_crb_category_thumb'] ?? 0) ?: (int) ($m['image'] ?? 0));
        $images = app(Attachments::class)->urls($imageIds->filter()->all());

        return $terms->map(function ($term) use ($meta, $images, $imageIds, $type, $translated, $counts) {
            $term_meta = $meta->get($term->term_id, []);
            $own = $translated[(int) $term->term_id] ?? null;

            return [
                'id' => (int) $term->term_id,
                // The term of the page's language: its SEO text and Yoast fields.
                'ownId' => $own->term_id ?? (int) $term->term_id,
                'name' => $own->name ?? html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5),
                'slug' => $own->slug ?? $term->slug,
                'wpSlug' => $own->wpSlug ?? $term->slug,
                'type' => $type,
                'count' => $own ? $counts[$term->term_taxonomy_id] : (int) $term->count,
                'image' => $images[$imageIds->get($term->term_id, 0)] ?? '',
                'meta' => $term_meta,
            ];
        })->all();
    }
}
