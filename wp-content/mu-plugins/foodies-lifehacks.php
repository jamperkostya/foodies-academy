<?php
/**
 * Plugin Name: Foodies lifehacks
 * Description: Post type "lifehack" (Лайфхаки) with categories, for /lifehacks on the site.
 *              Title, excerpt (card text), featured image, author, date and comments are the usual
 *              post fields; the rest of the page is in the "Лайфхак" box (post meta lifehack_*).
 */

if (!defined('ABSPATH')) {
	exit;
}

// meta key => [label, input type, hint]
const FOODIES_LIFEHACK_FIELDS = [
	'lifehack_icon' => ['Эмодзи для карточки', 'text', 'Например 🍝'],
	'lifehack_title_accent' => ['Продолжение заголовка', 'text', 'Вторая, меньшая строка заголовка, например «— и как их избежать»'],
	'lifehack_intro' => ['Вступление', 'textarea', 'Абзац перед пунктами'],
	'lifehack_points' => ['Пункты', 'textarea', 'Один пункт на строку: Заголовок | Текст'],
	'lifehack_tip_title' => ['Совет: заголовок', 'text', 'Например «Главное правило»'],
	'lifehack_tip_text' => ['Совет: текст', 'textarea', ''],
	'lifehack_video_minutes' => ['Длительность видео, мин', 'number', 'Пусто — без кнопки видео'],
];

add_action('init', function () {
	register_post_type('lifehack', [
		'labels' => [
			'name' => 'Лайфхаки',
			'singular_name' => 'Лайфхак',
			'add_new_item' => 'Добавить лайфхак',
			'edit_item' => 'Редактировать лайфхак',
			'all_items' => 'Все лайфхаки',
			'not_found' => 'Лайфхаков нет',
		],
		'public' => true,
		'show_in_rest' => true,
		'menu_icon' => 'dashicons-lightbulb',
		'menu_position' => 7,
		'has_archive' => true,
		'rewrite' => ['slug' => 'lifehack'],
		'supports' => ['title', 'excerpt', 'thumbnail', 'comments', 'author', 'custom-fields'],
	]);

	register_taxonomy('lifehack_category', 'lifehack', [
		'labels' => ['name' => 'Рубрики лайфхаков', 'singular_name' => 'Рубрика', 'add_new_item' => 'Добавить рубрику'],
		'hierarchical' => true,
		'show_in_rest' => true,
		'show_admin_column' => true,
		'rewrite' => ['slug' => 'lifehacks'],
	]);

	foreach (array_keys(FOODIES_LIFEHACK_FIELDS) as $key) {
		register_post_meta('lifehack', $key, ['single' => true, 'type' => 'string', 'show_in_rest' => true]);
	}
});

add_action('add_meta_boxes_lifehack', function () {
	add_meta_box('foodies-lifehack', 'Лайфхак', function (WP_Post $post) {
		wp_nonce_field('foodies_lifehack', 'foodies_lifehack_nonce');
		echo '<table class="form-table" role="presentation">';
		foreach (FOODIES_LIFEHACK_FIELDS as $key => [$label, $type, $hint]) {
			$value = (string) get_post_meta($post->ID, $key, true);
			$input = $type === 'textarea'
				? sprintf('<textarea class="large-text" rows="%d" id="%s" name="%2$s">%s</textarea>', $key === 'lifehack_points' ? 10 : 4, esc_attr($key), esc_textarea($value))
				: sprintf('<input class="%s" type="%s" id="%s" name="%3$s" value="%s"%s>', $type === 'number' ? 'small-text' : 'regular-text', $type, esc_attr($key), esc_attr($value), $type === 'number' ? ' min="0"' : '');
			printf('<tr><th scope="row"><label for="%s">%s</label></th><td>%s%s</td></tr>', esc_attr($key), esc_html($label), $input, $hint ? '<p class="description">' . esc_html($hint) . '</p>' : '');
		}
		echo '</table><p class="description">Отрывок — текст карточки и описание под заголовком; изображение записи — картинка лайфхака.</p>';
	}, 'lifehack', 'normal', 'high');
});

add_action('save_post_lifehack', function (int $post_id) {
	if (!isset($_POST['foodies_lifehack_nonce'])
		|| !wp_verify_nonce($_POST['foodies_lifehack_nonce'], 'foodies_lifehack')
		|| (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
		|| !current_user_can('edit_post', $post_id)) {
		return;
	}
	foreach (FOODIES_LIFEHACK_FIELDS as $key => [, $type]) {
		if (!isset($_POST[$key])) {
			continue;
		}
		$value = wp_unslash($_POST[$key]);
		$value = $type === 'textarea' ? sanitize_textarea_field($value) : ($type === 'number' ? (string) absint($value) : sanitize_text_field($value));
		($value === '' || $value === '0') ? delete_post_meta($post_id, $key) : update_post_meta($post_id, $key, $value);
	}
});
