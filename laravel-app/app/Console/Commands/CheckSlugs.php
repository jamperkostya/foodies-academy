<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Finds addresses the site can't build: slugs with characters other than a-z, 0-9, "-", "_"
 * (the routes accept only these), the same slug twice within a type, a dish type and a country
 * sharing /category/{slug}, and a site page whose /{slug} is taken by a section of the site.
 */
class CheckSlugs extends Command
{
    protected $signature = 'slugs:check';

    protected $description = 'Check the slugs of all site content';

    private const VALID = '/^[a-z0-9_-]+$/';

    private const POST_TYPES = ['recipe', 'post', 'news', 'video', 'lifehack', 'page'];

    private const TAXONOMIES = ['course', 'cuisine', 'ingredient', 'category', 'post_tag', 'news_category', 'lifehack_category'];

    /** First path segments of astro-app/src/pages (Astro skips such site pages too). */
    private const RESERVED = [
        'about', 'author', 'authors', 'blog', 'categories', 'category', 'collections', 'course', 'cuisine', 'ingredient',
        'kitchens', 'lifehack', 'lifehacks', 'login', 'news', 'profile', 'recipe', 'register', 'search', 'video', '404',
    ];

    public function handle(): int
    {
        $db = DB::connection('site');
        $problems = [];

        foreach (self::POST_TYPES as $type) {
            $slugs = $db->table('posts')->where('post_type', $type)->where('post_status', 'publish')->pluck('post_name', 'ID');
            $problems = [...$problems, ...$this->check("$type", $slugs)];
        }
        $terms = [];
        foreach (self::TAXONOMIES as $taxonomy) {
            $terms[$taxonomy] = $db->table('term_taxonomy as tt')->join('terms as t', 't.term_id', '=', 'tt.term_id')
                ->where('tt.taxonomy', $taxonomy)->pluck('t.slug', 't.term_id');
            $problems = [...$problems, ...$this->check($taxonomy, $terms[$taxonomy])];
        }
        $authorIds = $db->table('posts')->whereIn('post_type', ['recipe', 'post', 'news'])->where('post_status', 'publish')->distinct()->pluck('post_author');
        $problems = [...$problems, ...$this->check('author', $db->table('users')->whereIn('ID', $authorIds)->pluck('user_nicename', 'ID'))];

        foreach ($terms['course']->intersect($terms['cuisine']) as $slug) {
            $problems[] = "/category/$slug is both a dish type and a country";
        }
        $sitePages = $db->table('posts as p')
            ->join('postmeta as m', fn ($join) => $join->on('m.post_id', '=', 'p.ID')->where('m.meta_key', 'foodies_site_page')->where('m.meta_value', '1'))
            ->where('p.post_status', 'publish')->pluck('p.post_name');
        foreach ($sitePages->intersect(array_diff(self::RESERVED, ['about'])) as $slug) {
            $problems[] = "site page /$slug is taken by a section of the site";
        }

        if (! $problems) {
            $this->info('All slugs are fine.');

            return self::SUCCESS;
        }
        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }

    /** @param \Illuminate\Support\Collection<int, string> $slugs id => slug */
    private function check(string $what, $slugs): array
    {
        $problems = [];
        foreach ($slugs as $id => $slug) {
            if (! preg_match(self::VALID, (string) $slug)) {
                $problems[] = "$what #$id: invalid slug \"".rawurldecode((string) $slug).'"';
            }
        }
        foreach ($slugs->countBy()->filter(fn ($n) => $n > 1) as $slug => $n) {
            $problems[] = "$what: slug \"$slug\" is used $n times";
        }

        return $problems;
    }
}
