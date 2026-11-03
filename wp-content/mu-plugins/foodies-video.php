<?php
/**
 * Plugin Name: Foodies video recipes
 * Description: Post type "video" for the video recipes of the YouTube channel foodies-academy.
 *              Laravel imports them (php artisan videos:sync) and refreshes the YouTube statistics;
 *              the site's own likes are Posts Like Dislike counters, comments are WordPress comments.
 */

if (!defined('ABSPATH')) {
	exit;
}

// YouTube data Laravel keeps in post meta, shown read-only in the editor.
const FOODIES_VIDEO_META = [
	'youtube_id' => 'ID видео',
	'youtube_published_at' => 'Опубликовано на YouTube',
	'youtube_duration' => 'Длительность',
	'youtube_views' => 'Просмотры на YouTube',
	'youtube_likes' => 'Лайки на YouTube',
	'youtube_comments' => 'Комментарии на YouTube',
	'youtube_synced_at' => 'Обновлено',
];

add_action('init', function () {
	register_post_type('video', [
		'labels' => [
			'name' => 'Видеорецепты',
			'singular_name' => 'Видеорецепт',
			'add_new_item' => 'Добавить видеорецепт',
			'edit_item' => 'Редактировать видеорецепт',
			'all_items' => 'Все видеорецепты',
			'search_items' => 'Найти видеорецепт',
			'not_found' => 'Видеорецептов нет',
		],
		'public' => true,
		'show_in_rest' => true,
		'menu_icon' => 'dashicons-video-alt3',
		'menu_position' => 6,
		'has_archive' => true,
		'rewrite' => ['slug' => 'video'],
		'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'comments', 'custom-fields'],
		'taxonomies' => ['course', 'cuisine'],
	]);

	foreach (array_keys(FOODIES_VIDEO_META) as $key) {
		register_post_meta('video', $key, ['single' => true, 'type' => 'string', 'show_in_rest' => true]);
	}
});

add_action('add_meta_boxes_video', function () {
	add_meta_box('foodies-video-youtube', 'YouTube', function (WP_Post $post) {
		$id = get_post_meta($post->ID, 'youtube_id', true);
		echo '<table class="form-table" role="presentation">';
		foreach (FOODIES_VIDEO_META as $key => $label) {
			$value = (string) get_post_meta($post->ID, $key, true);
			if ($key === 'youtube_id' && $value !== '') {
				$value = sprintf('<a href="https://www.youtube.com/watch?v=%1$s" target="_blank" rel="noopener">%1$s</a>', esc_attr($value));
			} else {
				$value = esc_html($value);
			}
			printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), $value ?: '—');
		}
		echo '</table><p class="description">Заполняется автоматически при синхронизации с YouTube.</p>';
		if (!$id) {
			echo '<p class="description">Не связано с видео на YouTube.</p>';
		}
	}, 'video', 'side');
});

// Admin list: YouTube views and the site's likes next to the title.
add_filter('manage_video_posts_columns', function (array $columns) {
	return array_merge($columns, ['youtube_views' => 'Просмотры YouTube', 'youtube_likes' => 'Лайки YouTube']);
});
add_action('manage_video_posts_custom_column', function (string $column, int $post_id) {
	if (isset(FOODIES_VIDEO_META[$column])) {
		echo esc_html(number_format_i18n((int) get_post_meta($post_id, $column, true)));
	}
}, 10, 2);

// Posts Like Dislike counts likes for the post types in its settings: videos too.
add_filter('option_pld_settings', function ($settings) {
	if (is_array($settings) && isset($settings['basic_settings'])) {
		$types = (array) ($settings['basic_settings']['post_types'] ?? []);
		if (!in_array('video', $types, true)) {
			$settings['basic_settings']['post_types'] = array_merge($types, ['video']);
		}
	}
	return $settings;
});
