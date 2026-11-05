<?php
/**
 * Plugin Name: Foodies slugs
 * Description: Keeps post and term slugs to a-z, 0-9, "-" and "_", the characters the site's addresses
 *              accept. Cyr2Lat already transliterates Cyrillic; this catches the rest (other scripts,
 *              emoji, capitals), which WordPress would otherwise keep percent-encoded.
 */

if (!defined('ABSPATH')) {
	exit;
}

function foodies_clean_slug(string $slug): string
{
	$slug = strtolower(rawurldecode($slug));
	$slug = function_exists('remove_accents') ? remove_accents($slug) : $slug;
	$slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug);
	$slug = trim(preg_replace('/-{2,}/', '-', $slug), '-');

	return $slug;
}

// Posts: after WordPress made the slug unique; a cleaned slug that clashes gets "-2", "-3"…
add_filter('wp_unique_post_slug', function ($slug, $post_id, $status, $type, $parent) {
	$clean = foodies_clean_slug((string) $slug);
	if ($clean === $slug || $clean === '') {
		return $clean === '' ? $type . '-' . $post_id : $slug;
	}
	global $wpdb;
	$candidate = $clean;
	for ($i = 2; $wpdb->get_var($wpdb->prepare(
		"SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = %s AND ID != %d LIMIT 1", $candidate, $type, $post_id
	)); $i++) {
		$candidate = "$clean-$i";
	}

	return $candidate;
}, 20, 5);

// Terms (dish types, countries, ingredients, rubrics).
add_filter('wp_unique_term_slug', function ($slug, $term) {
	$clean = foodies_clean_slug((string) $slug);
	if ($clean === $slug || $clean === '') {
		return $clean === '' ? 'term-' . md5((string) ($term->name ?? $slug)) : $slug;
	}
	$candidate = $clean;
	for ($i = 2; term_exists($candidate, $term->taxonomy ?? ''); $i++) {
		$candidate = "$clean-$i";
	}

	return $candidate;
}, 20, 2);
