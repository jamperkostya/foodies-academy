<?php

namespace App\Services;

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
            ->when($limit, fn ($query) => $query->limit($limit))
            ->get($this->columns());

        return $this->present($terms, $type);
    }

    /** One category or country by slug, with one page of its recipes (most viewed first), or null. */
    public function getBySlug(string $slug, int $page = 1, int $perPage = 24): ?array
    {
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
            ->orderByDesc('v.post_views_total');

        $type = array_search($term->taxonomy, self::TAXONOMIES, true);

        return $this->present(collect([$term]), $type)[0]
            + ['seoText' => app(SeoText::class)->forTerm((int) $term->term_id), 'seo' => app(Seo::class)->forTerm($term->taxonomy, $term->slug)]
            + app(Recipes::class)->paginate($posts, $page, $perPage);
    }

    private function query(string $type): Builder
    {
        $taxonomy = self::TAXONOMIES[$type]
            ?? throw new InvalidArgumentException("Unknown category type \"$type\", expected: ".implode(', ', array_keys(self::TAXONOMIES)));

        return $this->db->table('term_taxonomy as tt')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.count', '>', 0)
            ->where('tt.taxonomy', $taxonomy);
    }

    private function columns(): array
    {
        return ['t.term_id', 't.name', 't.slug', 'tt.count'];
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
        $meta = $this->meta($terms->pluck('term_id')->all());
        // An attachment id: the Carbon Fields image field, or the old theme's ACF field "image"
        // (the live database has only that one).
        $imageIds = $meta->map(fn ($m) => (int) ($m['_crb_category_thumb'] ?? 0) ?: (int) ($m['image'] ?? 0));
        $images = app(Attachments::class)->urls($imageIds->filter()->all());

        return $terms->map(function ($term) use ($meta, $images, $imageIds, $type) {
            $term_meta = $meta->get($term->term_id, []);

            return [
                'id' => (int) $term->term_id,
                'name' => html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5),
                'slug' => $term->slug,
                'type' => $type,
                'count' => (int) $term->count,
                'image' => $images[$imageIds->get($term->term_id, 0)] ?? '',
                'meta' => $term_meta,
            ];
        })->all();
    }

}
