<?php
/**
 * Plugin Name: Foodies SEO text
 * Description: "SEO-текст" field with the visual editor for pages, posts, recipes, videos, lifehacks,
 *              dish types, cuisines, ingredients and post categories.
 *              Saved as post meta / term meta "seo_text"; the site (Astro) shows it in the SEO block
 *              at the bottom of the page, on the first page of paginated lists only.
 */

if (!defined('ABSPATH')) {
	exit;
}

const FOODIES_SEO_TEXT_KEY = 'seo_text';
const FOODIES_SEO_TEXT_POST_TYPES = ['page', 'post', 'news', 'recipe', 'video', 'lifehack'];
// course = dish types, cuisine = countries, ingredient = ingredients (WP Ultimate Recipe),
// category = post categories.
const FOODIES_SEO_TEXT_TAXONOMIES = ['course', 'cuisine', 'ingredient', 'category', 'news_category', 'lifehack_category'];

function foodies_seo_text_editor(string $content, string $id): void
{
	wp_editor($content, $id, [
		'textarea_name' => FOODIES_SEO_TEXT_KEY,
		'textarea_rows' => 12,
		'media_buttons' => true,
	]);
}

// Posts, pages, recipes: a meta box under the content editor.
add_action('add_meta_boxes', function () {
	foreach (FOODIES_SEO_TEXT_POST_TYPES as $post_type) {
		add_meta_box('foodies-seo-text', 'SEO-текст', function (WP_Post $post) {
			wp_nonce_field('foodies_seo_text', 'foodies_seo_text_nonce');
			echo '<p class="description">Выводится в SEO-блоке внизу страницы. Пусто — блок не показывается.</p>';
			foodies_seo_text_editor((string) get_post_meta($post->ID, FOODIES_SEO_TEXT_KEY, true), 'foodies_seo_text_post');
		}, $post_type, 'normal', 'low');
	}
});

add_action('save_post', function (int $post_id) {
	if (!isset($_POST['foodies_seo_text_nonce'])
		|| !wp_verify_nonce($_POST['foodies_seo_text_nonce'], 'foodies_seo_text')
		|| (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
		|| wp_is_post_revision($post_id)
		|| !current_user_can('edit_post', $post_id)) {
		return;
	}
	foodies_seo_text_save('post', $post_id);
});

// Categories: a field on the "add" and "edit" term screens.
foreach (FOODIES_SEO_TEXT_TAXONOMIES as $taxonomy) {
	add_action("{$taxonomy}_add_form_fields", function () {
		wp_nonce_field('foodies_seo_text', 'foodies_seo_text_nonce');
		echo '<div class="form-field"><label for="foodies_seo_text_term">SEO-текст</label>';
		foodies_seo_text_editor('', 'foodies_seo_text_term');
		echo '<p>Выводится в SEO-блоке на первой странице категории.</p></div>';
	});

	add_action("{$taxonomy}_edit_form_fields", function (WP_Term $term) {
		echo '<tr class="form-field"><th scope="row"><label for="foodies_seo_text_term">SEO-текст</label></th><td>';
		wp_nonce_field('foodies_seo_text', 'foodies_seo_text_nonce');
		foodies_seo_text_editor((string) get_term_meta($term->term_id, FOODIES_SEO_TEXT_KEY, true), 'foodies_seo_text_term');
		echo '<p class="description">Выводится в SEO-блоке на первой странице категории. Пусто — блок не показывается.</p></td></tr>';
	});

	$save_term = function (int $term_id) use ($taxonomy) {
		if (!isset($_POST['foodies_seo_text_nonce'])
			|| !wp_verify_nonce($_POST['foodies_seo_text_nonce'], 'foodies_seo_text')
			|| !current_user_can(get_taxonomy($taxonomy)->cap->edit_terms ?? 'manage_categories')) {
			return;
		}
		foodies_seo_text_save('term', $term_id);
	};
	add_action("created_{$taxonomy}", $save_term);
	add_action("edited_{$taxonomy}", $save_term);
}

function foodies_seo_text_save(string $type, int $id): void
{
	if (!isset($_POST[FOODIES_SEO_TEXT_KEY])) {
		return;
	}
	$html = trim(wp_kses_post(wp_unslash($_POST[FOODIES_SEO_TEXT_KEY])));
	$update = $type === 'post' ? 'update_post_meta' : 'update_term_meta';
	$delete = $type === 'post' ? 'delete_post_meta' : 'delete_term_meta';
	$html === '' ? $delete($id, FOODIES_SEO_TEXT_KEY) : $update($id, FOODIES_SEO_TEXT_KEY, $html);
}

// The add-term form is submitted by AJAX, which doesn't run the TinyMCE save:
// copy the editor content to its textarea before WordPress sends the form.
add_action('admin_footer-edit-tags.php', function () {
	?>
	<script>
		jQuery(function ($) {
			$('#submit').on('mousedown', function () {
				if (window.tinyMCE) tinyMCE.triggerSave();
			});
			$(document).ajaxComplete(function (event, xhr, settings) {
				if (settings.data && settings.data.indexOf('action=add-tag') !== -1 && window.tinyMCE) {
					var editor = tinyMCE.get('foodies_seo_text_term');
					if (editor) editor.setContent('');
				}
			});
		});
	</script>
	<?php
});
