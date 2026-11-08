<?php
/**
 * Plugin Name: Foodies site rebuild
 * Description: Tells Laravel that site content changed (a post published, edited or removed, a term
 *              edited, a comment approved), so it clears its cache and rebuilds the static site a few
 *              minutes later. Needs FOODIES_LARAVEL_URL and FOODIES_API_SECRET in the environment.
 */

if (!defined('ABSPATH')) {
	exit;
}

/** Post types whose content the site shows. */
const FOODIES_REBUILD_POST_TYPES = ['recipe', 'post', 'news', 'page', 'video', 'lifehack'];

/**
 * Notes a change; Laravel hears of all of them once, when WordPress has finished the request.
 * $item says which post or term it was, so Laravel forgets only its Yoast SEO fields:
 * ['post_type' => 'recipe', 'slug' => 'borscht', 'id' => 5] or ['taxonomy' => 'course', 'slug' => 'soups'].
 */
function foodies_rebuild_request(string $reason, array $item = []): void
{
	static $reasons = [], $items = [];
	if (!$reasons) {
		add_action('shutdown', function () use (&$reasons, &$items) {
			foodies_rebuild_send(implode('; ', array_unique($reasons)), $items);
		});
	}
	$reasons[] = $reason;
	if ($item) {
		$items[] = $item;
	}
}

function foodies_rebuild_send(string $reason, array $items): void
{
	$url = (string) getenv('FOODIES_LARAVEL_URL');
	$secret = (string) getenv('FOODIES_API_SECRET');
	if ($url === '' || strlen($secret) < 32) {
		return;
	}
	wp_remote_post(rtrim($url, '/') . '/api/internal/content-changed', [
		'blocking' => false, // Don't make the editor wait.
		'timeout' => 2,
		'headers' => ['X-Foodies-Secret' => $secret, 'Accept' => 'application/json'],
		'body' => ['reason' => mb_substr($reason, 0, 200), 'changed' => array_slice($items, 0, 50)],
	]);
}

// Published, unpublished, edited while published, trashed.
add_action('transition_post_status', function (string $new, string $old, WP_Post $post) {
	if (in_array($post->post_type, FOODIES_REBUILD_POST_TYPES, true) && ($new === 'publish' || $old === 'publish')) {
		foodies_rebuild_request("{$post->post_type} {$post->post_name}: $old → $new", ['post_type' => $post->post_type, 'slug' => $post->post_name, 'id' => $post->ID]);
	}
}, 10, 3);

add_action('before_delete_post', function (int $id, WP_Post $post) {
	if ($post->post_status === 'publish' && in_array($post->post_type, FOODIES_REBUILD_POST_TYPES, true)) {
		foodies_rebuild_request("{$post->post_type} {$post->post_name} deleted");
	}
}, 10, 2);

// Rubrics, dish types, countries, ingredients: names, SEO text.
foreach (['created_term', 'edited_term', 'delete_term'] as $hook) {
	add_action($hook, function ($term_id, $tt_id, $taxonomy) use ($hook) {
		$term = get_term($term_id, $taxonomy);
		foodies_rebuild_request("$hook $taxonomy #$term_id", $term instanceof WP_Term ? ['taxonomy' => $taxonomy, 'slug' => $term->slug] : []);
	}, 10, 3);
}

// Reviews and comments show on the site once approved.
add_action('transition_comment_status', function (string $new, string $old) {
	if ($new === 'approved' || $old === 'approved') {
		foodies_rebuild_request("comment $old → $new");
	}
}, 10, 2);
add_action('comment_post', function (int $id, $approved) {
	if ((int) $approved === 1) {
		foodies_rebuild_request('comment approved on posting');
	}
}, 10, 2);
