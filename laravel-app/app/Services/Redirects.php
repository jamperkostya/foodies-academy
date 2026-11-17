<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Addresses of the old WordPress site that search engines still know, for the site's .htaccess:
 * shortlinks /?p=ID and recipe slugs changed in the admin (WordPress keeps them in _wp_old_slug).
 */
class Redirects
{
    // Post type => its address on the site.
    private const PATHS = ['recipe' => '/recipe/', 'post' => '/blog/'];

    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    /** {ids: [{id, to}], slugs: [{from, to}]}, `from` without the leading slash. */
    public function get(): array
    {
        $posts = $this->db->table('posts')
            ->whereIn('post_type', array_keys(self::PATHS))
            ->where('post_status', 'publish')
            ->orderBy('ID')
            ->get(['ID', 'post_type', 'post_name']);

        $ids = $posts->map(fn ($post) => ['id' => (int) $post->ID, 'to' => self::PATHS[$post->post_type].$post->post_name]);

        $recipes = $posts->where('post_type', 'recipe')->pluck('post_name', 'ID');
        $live = $recipes->flip();
        $slugs = $this->db->table('postmeta')
            ->where('meta_key', '_wp_old_slug')
            ->whereIn('post_id', $recipes->keys())
            ->orderByDesc('meta_id')
            ->get(['post_id', 'meta_value'])
            // Cyrillic slugs are stored percent-encoded; Apache matches the decoded address.
            ->map(fn ($row) => ['old' => rawurldecode($row->meta_value), 'id' => (int) $row->post_id])
            // WordPress hands an old slug on to a new recipe: the live page keeps its address.
            ->filter(fn ($row) => preg_match('/^[\p{L}0-9_-]+$/u', $row['old']) && ! $live->has($row['old']))
            // The same old slug on several recipes: the newest rename wins.
            ->unique('old')
            ->map(fn ($row) => ['from' => 'recipe/'.$row['old'], 'to' => '/recipe/'.$recipes[$row['id']]])
            ->values();

        return ['ids' => $ids->values()->all(), 'slugs' => $slugs->all()];
    }
}
