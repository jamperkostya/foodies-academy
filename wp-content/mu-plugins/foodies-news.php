<?php
/**
 * Plugin Name: Foodies news
 * Description: Post type "news" (Новости) with its own categories, for /news on the site.
 *              Ordinary posts (post_type "post") are the blog (/blog).
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('init', function () {
	register_post_type('news', [
		'labels' => [
			'name' => 'Новости',
			'singular_name' => 'Новость',
			'add_new_item' => 'Добавить новость',
			'edit_item' => 'Редактировать новость',
			'all_items' => 'Все новости',
			'not_found' => 'Новостей нет',
		],
		'public' => true,
		'show_in_rest' => true,
		'rest_base' => 'news',
		'menu_icon' => 'dashicons-megaphone',
		'menu_position' => 5,
		'has_archive' => true,
		'rewrite' => ['slug' => 'news'],
		'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'author', 'comments', 'custom-fields'],
	]);

	register_taxonomy('news_category', 'news', [
		'labels' => ['name' => 'Рубрики новостей', 'singular_name' => 'Рубрика', 'add_new_item' => 'Добавить рубрику'],
		'hierarchical' => true,
		'show_in_rest' => true,
		'show_admin_column' => true,
		'rewrite' => ['slug' => 'news-category'],
	]);
});
