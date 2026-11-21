<?php

namespace App\Services;

use App\Support\Lang;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Ingredients (the WP Ultimate Recipe "ingredient" taxonomy) and their recipes. */
class Ingredients
{
    /**
     * An ingredient's page is indexed from this many recipes, or with an SEO text of its own of
     * this many characters (templated texts are shorter): below that, hundreds of pages show one
     * and the same recipe card, so they get "noindex, follow" and are left out of the sitemap.
     */
    private const INDEXED_FROM = 3;

    private const OWN_TEXT = 1500;

    private ConnectionInterface $db;

    public function __construct(private Recipes $recipes)
    {
        $this->db = DB::connection('site');
    }

    /** The ingredients used in the most recipes. */
    public function getPopular(int $limit = 5): array
    {
        if (! Lang::isDefault()) {
            // Counted by translated recipes.
            return collect($this->getAll())->sortByDesc('count')->take($limit)->values()->all();
        }

        return $this->db->table('term_taxonomy as tt')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'ingredient')
            ->where('tt.count', '>', 0)
            ->tap(fn ($q) => app(Translations::class)->scopeTerms($q))
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

    /**
     * Every ingredient with a published recipe, by name: each has its page on the site. Counted
     * here: term_taxonomy.count of the old database also counts deleted and draft recipes.
     */
    public function getAll(): array
    {
        $texts = $this->withOwnText();
        if (! Lang::isDefault()) {
            return collect($this->groups())
                ->map(fn ($group) => ['name' => $group['name'], 'slug' => $group['slug'], 'count' => $group['count'], 'indexed' => $this->indexed($group['count'], isset($texts[$group['ownId']]))])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        }

        return $this->counted()
            ->map(fn ($term) => [
                'name' => html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5),
                'slug' => $term->slug,
                'count' => (int) $term->count,
                'indexed' => $this->indexed((int) $term->count, isset($texts[$term->term_id])),
            ])
            ->values()
            ->all();
    }

    private function indexed(int $count, bool $ownText): bool
    {
        return $count >= self::INDEXED_FROM || $ownText;
    }

    /** Ids of ingredient terms with a long SEO text of their own, as keys. */
    private function withOwnText(): array
    {
        return $this->db->table('termmeta')
            ->where('meta_key', 'seo_text')
            ->whereRaw('CHAR_LENGTH(meta_value) >= ?', [self::OWN_TEXT])
            ->pluck('term_id')
            ->flip()
            ->all();
    }

    /** Russian ingredient terms with their published recipes of the current language: {term_id, term_taxonomy_id, name, slug, count}. */
    private function counted()
    {
        return $this->db->table('term_taxonomy as tt')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->join('term_relationships as r', 'r.term_taxonomy_id', '=', 'tt.term_taxonomy_id')
            ->join('posts as p', function ($join) {
                $join->on('p.ID', '=', 'r.object_id')->where('p.post_type', 'recipe')->where('p.post_status', 'publish')->tap(fn ($q) => app(Translations::class)->scope($q));
            })
            ->where('tt.taxonomy', 'ingredient')
            ->tap(fn ($q) => app(Translations::class)->scopeTerms($q))
            ->groupBy('t.term_id', 'tt.term_taxonomy_id', 't.name', 't.slug')
            ->orderBy('t.name')
            ->get(['t.term_id', 'tt.term_taxonomy_id', 't.name', 't.slug', DB::raw('COUNT(*) as count')]);
    }

    private array $groups = [];

    /**
     * The ingredients of the current language (not Russian): Russian terms with translated recipes,
     * one per translated name. Two Russian terms of one ingredient ("Масло оливковое", "Оливковое
     * масло") are one "Olive oil" there, under the slug of the one with more recipes (one without a
     * number first):
     * lowercase name => {name, slug, wpSlug, ownId, termIds, ttIds, count}.
     */
    private function groups(): array
    {
        // Every English ingredient page needs them: counted once per cache period, not per page.
        return $this->groups[Lang::current()] ??= Cache::remember(Lang::key('ingredients:groups'), now()->addMinutes(10), function () {
            $rows = $this->counted();
            $translated = app(Translations::class)->terms($rows->pluck('term_id')->map(fn ($id) => (int) $id)->all());
            $groups = [];
            foreach ($rows->sortByDesc('count') as $row) {
                $own = $translated[(int) $row->term_id] ?? null;
                if (! $own) {
                    continue;
                }
                $key = mb_strtolower($own->name);
                $groups[$key] ??= ['name' => $own->name, 'slug' => $own->slug, 'wpSlug' => $own->wpSlug, 'ownId' => $own->term_id, 'termIds' => [], 'ttIds' => [], 'count' => 0];
                // A slug without a number wins: "onion" over "onion-2".
                if (preg_match('/-\d+$/', $groups[$key]['slug']) && ! preg_match('/-\d+$/', $own->slug)) {
                    $groups[$key] = ['slug' => $own->slug, 'wpSlug' => $own->wpSlug, 'ownId' => $own->term_id] + $groups[$key];
                }
                $groups[$key]['termIds'][] = (int) $row->term_id;
                $groups[$key]['ttIds'][] = (int) $row->term_taxonomy_id;
                $groups[$key]['count'] = (int) $row->count;
            }
            foreach ($groups as $key => $group) {
                if (count($group['ttIds']) > 1) {
                    // A recipe with both terms counts once.
                    $groups[$key]['count'] = $this->recipesWith($group['ttIds'])->distinct()->count('p.ID');
                }
            }

            return $groups;
        });
    }

    /** Published recipes of the current language with any of the terms, most viewed first. */
    private function recipesWith(array $ttIds)
    {
        return $this->db->table('posts as p')
            ->leftJoin('post_views_realtime as v', function ($join) {
                $join->on('v.post_id', '=', 'p.ID')
                    ->where('v.view_type', 'normal')
                    ->where('v.output_type', 'content');
            })
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')->tap(fn ($q) => app(Translations::class)->scope($q))
            // whereExists, not a join: a recipe with two of the terms shows up once
            ->whereExists(fn ($q) => $q->from('term_relationships as r')->whereColumn('r.object_id', 'p.ID')->whereIn('r.term_taxonomy_id', $ttIds))
            ->orderByDesc('v.post_views_total');
    }

    /**
     * The English address of the ingredient page of a Russian term, if the term is its group's
     * first: hreflang links must go both ways, and the English page names that one.
     */
    private function englishSlug(int $termId): ?string
    {
        $lang = Lang::current();
        Lang::set('en');
        try {
            $group = collect($this->groups())->first(fn ($group) => ($group['termIds'][0] ?? null) === $termId);
        } finally {
            Lang::set($lang);
        }

        return $group['slug'] ?? null;
    }

    /** One ingredient with one page of its recipes, most viewed first, or null. */
    public function getBySlug(string $slug, int $page = 1, int $perPage = 24): ?array
    {
        if (! Lang::isDefault()) {
            $group = collect($this->groups())->firstWhere('slug', $slug);
            if (! $group) {
                return null;
            }
            $list = $this->recipes->paginate($this->recipesWith($group['ttIds']), $page, $perPage);
            $source = $this->db->table('terms')->where('term_id', $group['termIds'][0])->value('slug');

            return [
                'name' => $group['name'],
                'slug' => $group['slug'],
                'count' => $list['total'],
                'indexed' => $this->indexed($list['total'], isset($this->withOwnText()[$group['ownId']])),
                'seoText' => app(SeoText::class)->forTerm($group['ownId']),
                'seo' => app(Seo::class)->forTerm('ingredient', $group['wpSlug']),
                'translations' => [Lang::DEFAULT => $source, Lang::current() => $group['slug']],
            ] + $list;
        }

        $term = $this->db->table('term_taxonomy as tt')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'ingredient')
            ->where('t.slug', $slug)
            ->first(['tt.term_taxonomy_id', 't.term_id', 't.name', 't.slug', 'tt.count']);
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
            ->where('p.post_status', 'publish')->tap(fn ($q) => app(Translations::class)->scope($q))
            ->orderByDesc('v.post_views_total');

        $list = $this->recipes->paginate($posts, $page, $perPage);
        $english = $this->englishSlug((int) $term->term_id);

        return [
            'name' => html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5),
            'slug' => $term->slug,
            'count' => (int) $term->count,
            'indexed' => $this->indexed($list['total'], isset($this->withOwnText()[$term->term_id])),
            'seoText' => app(SeoText::class)->forTerm((int) $term->term_id),
            'seo' => app(Seo::class)->forTerm('ingredient', $term->slug),
            // The English page of the ingredient, if it has translated recipes.
            'translations' => array_filter([Lang::DEFAULT => $term->slug, 'en' => $english]),
        ] + $list;
    }
}
