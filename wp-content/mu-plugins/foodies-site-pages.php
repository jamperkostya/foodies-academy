<?php
/**
 * Plugin Name: Foodies site pages
 * Description: WordPress pages the site (Astro) shows at /{slug}: legal pages, contacts, the home page
 *              promo blocks ("Get Started…", "Ingredient Guide"). A page is on the site when
 *              "Отдельная страница сайта" is ticked (post meta foodies_site_page = 1). The title, text,
 *              excerpt (short text of a home block) and featured image are edited here.
 */

if (!defined('ABSPATH')) {
	exit;
}

const FOODIES_SITE_PAGE_KEY = 'foodies_site_page';

// The pages the site links to, created once if missing. Existing pages with these slugs are only ticked.
add_action('init', function () {
	if (get_option('foodies_site_pages_version') === '1') {
		return;
	}
	$fill_in = '<p><strong>Заполните текст этой страницы в админке WordPress: Страницы → «%s».</strong></p>';
	$pages = [
		'get-started' => [
			'title' => 'Get Started. 10 Ingredients. Hundreds of Recipes',
			'excerpt' => 'Десять базовых ингредиентов — и сотни блюд, которые из них можно приготовить.',
		],
		'ingredient-guide' => [
			'title' => 'Ingredient Guide',
			'excerpt' => 'Как выбирать, хранить и сочетать продукты: короткий гид по ингредиентам.',
		],
		'privacy-policy' => ['title' => 'Политика конфиденциальности', 'excerpt' => ''],
		'terms' => ['title' => 'Правила использования', 'excerpt' => ''],
		'contacts' => ['title' => 'Контакты', 'excerpt' => ''],
	];
	foreach ($pages as $slug => $page) {
		$existing = get_page_by_path($slug, OBJECT, 'page');
		$id = $existing ? $existing->ID : wp_insert_post([
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_name' => $slug,
			'post_title' => $page['title'],
			'post_excerpt' => $page['excerpt'],
			'post_content' => sprintf($fill_in, $page['title']),
			'comment_status' => 'closed',
		]);
		if ($id && !is_wp_error($id)) {
			update_post_meta($id, FOODIES_SITE_PAGE_KEY, '1');
		}
	}
	// "О проекте" is on the site too.
	if ($about = get_page_by_path('about', OBJECT, 'page')) {
		update_post_meta($about->ID, FOODIES_SITE_PAGE_KEY, '1');
	}
	update_option('foodies_site_pages_version', '1');
});

// Pages need an excerpt field: it is the short text of a home page block.
add_action('init', function () {
	add_post_type_support('page', 'excerpt');
});

add_action('add_meta_boxes_page', function () {
	add_meta_box('foodies-site-page', 'Страница сайта', function (WP_Post $post) {
		wp_nonce_field('foodies_site_page', 'foodies_site_page_nonce');
		printf(
			'<label><input type="checkbox" name="foodies_site_page" value="1" %s> Отдельная страница сайта</label>
			<p class="description">Видна на сайте по адресу /%s. Отрывок — короткий текст для блока на главной.</p>',
			checked(get_post_meta($post->ID, FOODIES_SITE_PAGE_KEY, true), '1', false),
			esc_html($post->post_name ?: 'адрес-страницы')
		);
	}, 'page', 'side');
});

add_action('save_post_page', function (int $post_id) {
	if (!isset($_POST['foodies_site_page_nonce'])
		|| !wp_verify_nonce($_POST['foodies_site_page_nonce'], 'foodies_site_page')
		|| (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
		|| !current_user_can('edit_page', $post_id)) {
		return;
	}
	empty($_POST['foodies_site_page'])
		? delete_post_meta($post_id, FOODIES_SITE_PAGE_KEY)
		: update_post_meta($post_id, FOODIES_SITE_PAGE_KEY, '1');
});
