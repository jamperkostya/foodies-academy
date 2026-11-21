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

/**
 * Language (Polylang) of a post, a term or a comment's post: a change of a translation alone
 * rebuilds only its language's pages. "ru" without Polylang.
 */
function foodies_rebuild_lang(string $type, int $id): string
{
	$lang = match ($type) {
		'post' => function_exists('pll_get_post_language') ? pll_get_post_language($id) : null,
		'term' => function_exists('pll_get_term_language') ? pll_get_term_language($id) : null,
		default => null,
	};

	return $lang ?: 'ru';
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
		foodies_rebuild_request("{$post->post_type} {$post->post_name}: $old → $new", ['post_type' => $post->post_type, 'slug' => $post->post_name, 'id' => $post->ID, 'lang' => foodies_rebuild_lang('post', $post->ID)]);
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
		// Polylang keeps languages and links between translations as terms: not content.
		if (in_array($taxonomy, ['language', 'term_language', 'post_translations', 'term_translations'], true)) {
			return;
		}
		$term = get_term($term_id, $taxonomy);
		foodies_rebuild_request("$hook $taxonomy #$term_id", $term instanceof WP_Term ? ['taxonomy' => $taxonomy, 'slug' => $term->slug, 'lang' => foodies_rebuild_lang('term', $term_id)] : []);
	}, 10, 3);
}

// Interface strings translated in the admin (Polylang: Languages → Translations).
add_action('pll_save_strings_translations', function () {
	// Russian strings are the code's own: only the translated pages change.
	foodies_rebuild_request('interface strings translated', ['lang' => 'en']);
});

// Reviews and comments show on the site once approved.
// A review is on the page of its post's language only.
add_action('transition_comment_status', function (string $new, string $old, WP_Comment $comment) {
	if ($new === 'approved' || $old === 'approved') {
		foodies_rebuild_request("comment $old → $new", ['lang' => foodies_rebuild_lang('post', (int) $comment->comment_post_ID)]);
	}
}, 10, 3);
add_action('comment_post', function (int $id, $approved) {
	if ((int) $approved === 1) {
		foodies_rebuild_request('comment approved on posting', ['lang' => foodies_rebuild_lang('post', (int) get_comment($id)->comment_post_ID)]);
	}
}, 10, 2);

// Authors' name, photo and bio show on the site; other users' profiles don't.
add_action('profile_update', function (int $user_id) {
	if (count_user_posts($user_id, ['recipe', 'post', 'news'], true) > 0) {
		foodies_rebuild_request("author #$user_id profile");
	}
});
