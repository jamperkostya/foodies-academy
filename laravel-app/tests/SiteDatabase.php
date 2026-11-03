<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The WordPress tables for tests: created once per run from tests/fixtures/site-schema.sql in the
 * empty foodies_site_test database, emptied before every test, plus helpers to add rows.
 * Real HTTP is blocked: tests fake the WordPress/YouTube calls they need.
 */
trait SiteDatabase
{
    private static bool $siteSchemaLoaded = false;

    protected function setUpSiteDatabase(): void
    {
        $db = DB::connection('site');
        // Tables are dropped below: never anywhere but a test database.
        if (! str_ends_with($db->getDatabaseName(), '_test')) {
            throw new \RuntimeException("Refusing to reset \"{$db->getDatabaseName()}\": the site test database must end in _test (see phpunit.xml).");
        }
        if (! self::$siteSchemaLoaded) {
            foreach ($db->select('SHOW TABLES') as $row) {
                $db->statement('DROP TABLE `'.array_values((array) $row)[0].'`');
            }
            $sql = file_get_contents(__DIR__.'/fixtures/site-schema.sql');
            foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) {
                $db->unprepared($statement);
            }
            self::$siteSchemaLoaded = true;
        }
        foreach ($db->select('SHOW TABLES') as $row) {
            $db->statement('TRUNCATE TABLE `'.array_values((array) $row)[0].'`');
        }
        $this->site()->table('options')->insert([
            ['option_name' => 'gmt_offset', 'option_value' => '2', 'autoload' => 'yes'],
            ['option_name' => 'home', 'option_value' => 'http://wordpress.test', 'autoload' => 'yes'],
        ]);
        // No real HTTP: a test fakes the WordPress/YouTube calls it needs. Unfaked Yoast calls
        // fail, and the API then sends seo: null, as when WordPress is down.
        Http::preventStrayRequests();
    }

    protected function site()
    {
        return DB::connection('site');
    }

    /** A published recipe; returns its id. */
    protected function recipe(string $slug, array $post = [], array $meta = []): int
    {
        // No WPML row: the site is Russian only, recipes made in the admin have none.
        return $this->makePost('recipe', $slug, $post, $meta);
    }

    protected function makePost(string $type, string $slug, array $post = [], array $meta = []): int
    {
        $date = $post['post_date'] ?? '2026-01-10 12:00:00';
        $id = $this->site()->table('posts')->insertGetId($post + [
            'post_author' => 1,
            'post_date' => $date,
            'post_date_gmt' => $date,
            'post_content' => '',
            'post_title' => ucfirst(str_replace('-', ' ', $slug)),
            'post_excerpt' => '',
            'post_status' => 'publish',
            'comment_status' => 'open',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => $slug,
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $date,
            'post_modified_gmt' => $date,
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => '',
            'menu_order' => 0,
            'post_type' => $type,
            'post_mime_type' => '',
            'comment_count' => 0,
        ], 'ID');
        foreach ($meta as $key => $value) {
            $this->meta($id, $key, $value);
        }

        return $id;
    }

    protected function meta(int $postId, string $key, string $value): void
    {
        $this->site()->table('postmeta')->insert(['post_id' => $postId, 'meta_key' => $key, 'meta_value' => $value]);
    }

    protected function postMeta(int $postId, string $key): ?string
    {
        return $this->site()->table('postmeta')->where('post_id', $postId)->where('meta_key', $key)->value('meta_value');
    }

    protected function user(string $slug, string $name = 'Автор'): int
    {
        return $this->site()->table('users')->insertGetId([
            'user_login' => $slug,
            'user_pass' => '',
            'user_nicename' => $slug,
            'user_email' => "$slug@example.test",
            'user_url' => '',
            'user_registered' => '2020-01-01 00:00:00',
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => $name,
        ], 'ID');
    }

    /** A term of $taxonomy linked to the posts; returns the term id. */
    protected function term(string $taxonomy, string $slug, string $name, array $postIds = []): int
    {
        $termId = $this->site()->table('terms')->insertGetId(['name' => $name, 'slug' => $slug, 'term_group' => 0], 'term_id');
        $ttId = $this->site()->table('term_taxonomy')->insertGetId([
            'term_id' => $termId, 'taxonomy' => $taxonomy, 'description' => '', 'parent' => 0, 'count' => count($postIds),
        ], 'term_taxonomy_id');
        foreach ($postIds as $postId) {
            $this->site()->table('term_relationships')->insert(['object_id' => $postId, 'term_taxonomy_id' => $ttId, 'term_order' => 0]);
        }

        return $termId;
    }

    /** An approved (or pending) comment, optionally with stars; returns its id. */
    protected function comment(int $postId, string $author, array $fields = [], ?int $rating = null): int
    {
        $id = $this->site()->table('comments')->insertGetId($fields + [
            'comment_post_ID' => $postId,
            'comment_author' => $author,
            'comment_author_email' => '',
            'comment_author_url' => '',
            'comment_author_IP' => '',
            'comment_date' => '2026-01-10 12:00:00',
            'comment_date_gmt' => '2026-01-10 10:00:00',
            'comment_content' => "Отзыв от $author",
            'comment_karma' => 0,
            'comment_approved' => '1',
            'comment_agent' => '',
            'comment_type' => 'comment',
            'comment_parent' => 0,
            'user_id' => 0,
        ], 'comment_ID');
        if ($rating !== null) {
            $this->site()->table('commentmeta')->insert(['comment_id' => $id, 'meta_key' => 'rating', 'meta_value' => (string) $rating]);
        }

        return $id;
    }
}
