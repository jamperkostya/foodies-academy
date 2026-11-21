<?php

namespace App\Services;

use App\Support\Lang;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Translations as Polylang keeps them. The language of a post is a term of the "language"
 * taxonomy, of a term one of "term_language" ("pll_en"); the translations of one post or term
 * are listed in the description of a "post_translations" / "term_translations" term, serialized
 * ['ru' => 5, 'en' => 12]. Without Polylang everything is Russian and has no translations.
 *
 * The Russian post is the original: lists, counts, terms and photos are its. Another language
 * shows the Russian posts that have a published translation, with the translation's texts.
 */
class Translations
{
    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    /** Ids of posts in another language than Russian: the translations. */
    public function translatedIds(): array
    {
        return $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'language')
            ->where('t.slug', '<>', Lang::DEFAULT)
            ->pluck('r.object_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** The post and its translations, [language => id]; just the post when it has none. */
    public function group(int $postId): array
    {
        $value = $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->where('r.object_id', $postId)
            ->where('tt.taxonomy', 'post_translations')
            ->value('tt.description');
        $group = @unserialize((string) $value, ['allowed_classes' => false]);
        $group = is_array($group) ? array_map('intval', array_filter($group, 'is_numeric')) : [];

        return in_array($postId, $group, true) ? $group : [Lang::DEFAULT => $postId];
    }

    /** Slugs of a post and its published translations: [language => slug]. */
    public function slugs(int $postId): array
    {
        $group = $this->group($postId);
        $slugs = $this->db->table('posts')->whereIn('ID', $group)->where('post_status', 'publish')->pluck('post_name', 'ID');
        $source = (string) ($slugs[$group[Lang::DEFAULT] ?? 0] ?? '');

        return collect($group)->filter(fn ($id) => $slugs->has($id))->map(fn ($id, $lang) => $lang === Lang::DEFAULT ? $slugs[$id] : $this->publicSlug($slugs[$id], $source))->all();
    }

    /**
     * Slugs of a term and its translations that have published recipes in their language
     * (a page of each): [language => slug].
     */
    public function termSlugs(int $termId): array
    {
        $taxonomyIds = $this->db->table('term_taxonomy')->where('term_id', $termId)->pluck('term_taxonomy_id');
        $hasRecipes = fn (string $lang) => $lang === Lang::DEFAULT || $this->translationQuery($this->db->query(), $lang)
            ->join('term_relationships as tt_r', 'tt_r.object_id', '=', 'tr_source.object_id')
            ->whereIn('tt_r.term_taxonomy_id', $taxonomyIds)
            ->exists();

        return array_filter($this->allTermSlugs($termId), fn ($slug, $lang) => $hasRecipes($lang), ARRAY_FILTER_USE_BOTH);
    }

    private function allTermSlugs(int $termId): array
    {
        $value = $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->where('r.object_id', $termId)
            ->where('tt.taxonomy', 'term_translations')
            ->value('tt.description');
        $group = @unserialize((string) $value, ['allowed_classes' => false]);
        $group = is_array($group) && in_array($termId, array_map('intval', $group), true) ? array_map('intval', $group) : [Lang::DEFAULT => $termId];
        $slugs = $this->db->table('terms')->whereIn('term_id', $group)->pluck('slug', 'term_id');
        $source = (string) ($slugs[$group[Lang::DEFAULT] ?? 0] ?? '');

        return collect($group)->filter(fn ($id) => $slugs->has($id))->map(fn ($id, $lang) => $lang === Lang::DEFAULT ? $slugs[$id] : $this->publicSlug($slugs[$id], $source))->all();
    }

    /**
     * Limits a query of posts ($column is their id) to the originals of the current language:
     * Russian posts, and in another language only those with a published translation into it.
     */
    public function scope(Builder $query, string $column = 'p.ID'): Builder
    {
        $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('term_relationships as lang_r')
            ->join('term_taxonomy as lang_tt', fn ($j) => $j->on('lang_tt.term_taxonomy_id', '=', 'lang_r.term_taxonomy_id')->where('lang_tt.taxonomy', 'language'))
            ->join('terms as lang_t', fn ($j) => $j->on('lang_t.term_id', '=', 'lang_tt.term_id')->where('lang_t.slug', '<>', Lang::DEFAULT))
            ->whereColumn('lang_r.object_id', $column));
        if (Lang::isDefault()) {
            return $query;
        }

        return $query->whereExists(fn ($q) => $this->translationQuery($q, Lang::current())->selectRaw('1')->whereColumn('tr_source.object_id', $column));
    }

    /**
     * Published translations into the current language of Russian posts:
     * source id => post row (ID, post_name, post_title, post_content, post_modified_gmt, post_date_gmt).
     */
    public function posts(array $sourceIds): array
    {
        if (Lang::isDefault() || ! $sourceIds) {
            return [];
        }

        $rows = $this->translationQuery($this->db->query(), Lang::current())
            ->join('posts as src_post', 'src_post.ID', '=', 'tr_source.object_id')
            ->whereIn('tr_source.object_id', $sourceIds)
            ->get(['tr_source.object_id as source_id', 'src_post.post_name as source_slug', 'tr_post.ID', 'tr_post.post_name', 'tr_post.post_title', 'tr_post.post_content', 'tr_post.post_modified_gmt', 'tr_post.post_date_gmt']);
        foreach ($rows as $row) {
            $row->post_name = $this->publicSlug($row->post_name, $row->source_slug);
        }

        return $rows->keyBy(fn ($row) => (int) $row->source_id)->all();
    }

    /** The published post of $type with $slug in the current language and its Russian original's id, or null. */
    public function find(string $type, string $slug): ?array
    {
        if (Lang::isDefault()) {
            return null;
        }
        $query = fn () => $this->translationQuery($this->db->query(), Lang::current())
            ->join('posts as src_post', 'src_post.ID', '=', 'tr_source.object_id')
            ->where('tr_post.post_type', $type);
        $row = $query()->where('tr_post.post_name', $slug)->first(['tr_source.object_id as source_id', 'src_post.post_name as source_slug', 'tr_post.ID', 'tr_post.post_name']);
        // Shown under the original's slug (publicSlug()): "spaghetti-alla-carbonara" for "-2".
        $row ??= $query()->where('src_post.post_name', $slug)->first(['tr_source.object_id as source_id', 'src_post.post_name as source_slug', 'tr_post.ID', 'tr_post.post_name']);
        if (! $row || $this->publicSlug($row->post_name, $row->source_slug) !== $slug) {
            return null;
        }

        return ['id' => (int) $row->ID, 'source' => (int) $row->source_id];
    }

    /**
     * Translations into the current language of Russian terms: term_id => {term_id, name, slug}.
     * A term without one is left out.
     */
    public function terms(array $termIds): array
    {
        if (Lang::isDefault() || ! $termIds) {
            return [];
        }
        $groups = $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->whereIn('r.object_id', $termIds)
            ->where('tt.taxonomy', 'term_translations')
            ->pluck('tt.description', 'r.object_id');
        $map = [];
        foreach ($groups as $termId => $value) {
            $group = @unserialize((string) $value, ['allowed_classes' => false]);
            if (is_array($group) && isset($group[Lang::current()]) && (int) ($group[Lang::DEFAULT] ?? 0) === (int) $termId) {
                $map[(int) $group[Lang::current()]] = (int) $termId;
            }
        }
        if (! $map) {
            return [];
        }
        $terms = $this->db->table('terms')->whereIn('term_id', array_keys($map))->get(['term_id', 'name', 'slug']);
        $sourceSlugs = $this->db->table('terms')->whereIn('term_id', array_values($map))->pluck('slug', 'term_id');
        $result = [];
        foreach ($terms as $term) {
            $source = $map[(int) $term->term_id];
            $result[$source] = (object) [
                'term_id' => (int) $term->term_id,
                'name' => html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5),
                'slug' => $this->publicSlug($term->slug, (string) ($sourceSlugs[$source] ?? '')),
                // WordPress's own: its REST API (Yoast's fields) knows only this one.
                'wpSlug' => $term->slug,
            ];
        }

        return $result;
    }

    /**
     * The slug a translated term is shown under: WordPress keeps slugs unique, so "soups" of the
     * Russian term made the English one "soups-2"; the address then uses "soups". The same for posts.
     */
    private function publicSlug(string $slug, string $sourceSlug): string
    {
        return $sourceSlug !== '' && preg_match('/^'.preg_quote($sourceSlug, '/').'-\d+$/', $slug) ? $sourceSlug : $slug;
    }

    /** The Russian term of $taxonomies whose translation into the current language has $slug: its slug, or null. */
    public function termSource(array $taxonomies, string $slug): ?string
    {
        $term = $this->db->table('terms as t')
            ->join('term_taxonomy as tt', 'tt.term_id', '=', 't.term_id')
            ->whereIn('tt.taxonomy', $taxonomies)
            ->where('t.slug', $slug)
            ->value('t.term_id');
        if (! $term) {
            return null;
        }
        // A Russian term shown under its own slug (see publicSlug()).
        $own = $this->terms([(int) $term])[(int) $term] ?? null;
        if ($own && $own->slug === $slug) {
            return $slug;
        }
        $value = $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->where('r.object_id', $term)
            ->where('tt.taxonomy', 'term_translations')
            ->value('tt.description');
        $group = @unserialize((string) $value, ['allowed_classes' => false]);
        if (! is_array($group) || (int) ($group[Lang::current()] ?? 0) !== (int) $term || ! isset($group[Lang::DEFAULT])) {
            return null;
        }

        return $this->db->table('terms')->where('term_id', (int) $group[Lang::DEFAULT])->value('slug');
    }

    /** Limits a query of terms ($column is their term_id) to Russian ones: a translated term is not a term of its own. */
    public function scopeTerms(Builder $query, string $column = 't.term_id'): Builder
    {
        $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('term_relationships as tl_r')
            ->join('term_taxonomy as tl_tt', fn ($j) => $j->on('tl_tt.term_taxonomy_id', '=', 'tl_r.term_taxonomy_id')->where('tl_tt.taxonomy', 'term_language'))
            ->join('terms as tl_t', fn ($j) => $j->on('tl_t.term_id', '=', 'tl_tt.term_id')->where('tl_t.slug', '<>', 'pll_'.Lang::DEFAULT))
            ->whereColumn('tl_r.object_id', $column));

        return $query;
    }

    /** Joins a post (tr_source.object_id) to its published translation into $lang (tr_post). */
    private function translationQuery(Builder $query, string $lang): Builder
    {
        return $query->from('term_relationships as tr_source')
            ->join('term_taxonomy as tr_group', fn ($j) => $j->on('tr_group.term_taxonomy_id', '=', 'tr_source.term_taxonomy_id')->where('tr_group.taxonomy', 'post_translations'))
            ->join('term_relationships as tr_member', 'tr_member.term_taxonomy_id', '=', 'tr_source.term_taxonomy_id')
            ->join('posts as tr_post', fn ($j) => $j->on('tr_post.ID', '=', 'tr_member.object_id')->where('tr_post.post_status', 'publish'))
            ->join('term_relationships as tr_lang_r', 'tr_lang_r.object_id', '=', 'tr_post.ID')
            ->join('term_taxonomy as tr_lang_tt', fn ($j) => $j->on('tr_lang_tt.term_taxonomy_id', '=', 'tr_lang_r.term_taxonomy_id')->where('tr_lang_tt.taxonomy', 'language'))
            ->join('terms as tr_lang', fn ($j) => $j->on('tr_lang.term_id', '=', 'tr_lang_tt.term_id')->where('tr_lang.slug', $lang));
    }
}
