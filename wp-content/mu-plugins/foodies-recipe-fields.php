<?php
/**
 * Plugin Name: Foodies recipe fields
 * Description: Recipe fields of the old theme, kept in ACF / Carbon Fields meta that can't be edited
 *              without those plugins, as boxes on the recipe screen:
 *              - "Галерея фото": photos from the media library, in the order they are dragged to.
 *                Post meta "gallery", serialized attachment IDs (ACF's format). The site (Astro)
 *                shows the featured image first, then these photos.
 *              - "История происхождения": HTML in post meta "history" (ACF's key). The site shows
 *                it in the origin story block, nothing when it is empty.
 */

if (!defined('ABSPATH')) {
	exit;
}

const FOODIES_GALLERY_KEY = 'gallery';
const FOODIES_HISTORY_KEY = 'history';
// The same text saved by Carbon Fields. Laravel reads it first, so it is dropped when the box saves.
const FOODIES_HISTORY_CARBON_KEY = '_crb_recipe_history';

/** Attachment IDs of the recipe's gallery, in their order. */
function foodies_gallery_ids(int $post_id): array
{
	$ids = get_post_meta($post_id, FOODIES_GALLERY_KEY, true);

	return is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
}

add_action('add_meta_boxes_recipe', function (WP_Post $post) {
	add_meta_box('foodies-gallery', 'Галерея фото', function (WP_Post $post) {
		wp_nonce_field('foodies_gallery', 'foodies_gallery_nonce');
		$ids = foodies_gallery_ids($post->ID);
		echo '<p class="description">Фото карусели на странице рецепта, после изображения записи. Порядок меняется перетаскиванием.</p>';
		echo '<ul class="foodies-gallery">';
		foreach ($ids as $id) {
			$thumb = wp_get_attachment_image($id, 'thumbnail');
			// A photo deleted from the media library: shown so it can be removed from the list.
			printf(
				'<li data-id="%d">%s<button type="button" class="foodies-gallery__remove" aria-label="Убрать">×</button></li>',
				$id,
				$thumb ?: '<span class="foodies-gallery__missing">нет файла #' . $id . '</span>'
			);
		}
		echo '</ul>';
		printf('<input type="hidden" name="foodies_gallery" value="%s">', esc_attr(implode(',', $ids)));
		echo '<p><button type="button" class="button foodies-gallery__add">Добавить фото</button></p>';
	}, 'recipe', 'normal', 'high');

	add_meta_box('foodies-history', 'История происхождения', function (WP_Post $post) {
		wp_nonce_field('foodies_history', 'foodies_history_nonce');
		$history = (string) (get_post_meta($post->ID, FOODIES_HISTORY_CARBON_KEY, true) ?: get_post_meta($post->ID, FOODIES_HISTORY_KEY, true));
		echo '<p class="description">Выводится в блоке «История происхождения» на странице рецепта. Пусто — блок не показывается.</p>';
		wp_editor($history, 'foodies_history', ['textarea_name' => 'foodies_history', 'textarea_rows' => 12, 'media_buttons' => true]);
	}, 'recipe', 'normal', 'high');
});

add_action('admin_enqueue_scripts', function (string $hook) {
	if (!in_array($hook, ['post.php', 'post-new.php'], true) || get_current_screen()->post_type !== 'recipe') {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_script('jquery-ui-sortable');
	wp_add_inline_style('wp-admin', '
		.foodies-gallery { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }
		.foodies-gallery li { position: relative; margin: 0; cursor: move; }
		.foodies-gallery img, .foodies-gallery__missing { display: block; width: 100px; height: 100px; object-fit: cover; }
		.foodies-gallery__missing { display: flex; align-items: center; justify-content: center; background: #f0f0f1; font-size: 11px; }
		.foodies-gallery__remove { position: absolute; top: 2px; right: 2px; width: 22px; height: 22px; border: 0; border-radius: 50%; background: #d63638; color: #fff; cursor: pointer; line-height: 1; }
	');
	wp_add_inline_script('jquery-ui-sortable', <<<'JS'
		jQuery(function ($) {
			const box = $("#foodies-gallery");
			const list = box.find(".foodies-gallery");
			const input = box.find('input[name="foodies_gallery"]');
			const sync = () => input.val(list.children().map((_, li) => $(li).data("id")).get().join(","));
			const remove = '<button type="button" class="foodies-gallery__remove" aria-label="Убрать">×</button>';

			list.sortable({ update: sync });
			list.on("click", ".foodies-gallery__remove", function () { $(this).parent().remove(); sync(); });

			let frame;
			box.on("click", ".foodies-gallery__add", function () {
				frame ??= wp.media({ title: "Фото галереи", button: { text: "Добавить" }, library: { type: "image" }, multiple: "add" });
				frame.off("select").on("select", function () {
					frame.state().get("selection").each(function (photo) {
						if (list.children('[data-id="' + photo.id + '"]').length) return;
						const size = photo.get("sizes")?.thumbnail ?? photo.get("sizes")?.full;
						list.append($("<li>").attr("data-id", photo.id).append($("<img>").attr({ src: size?.url ?? photo.get("url"), alt: "" }), remove));
					});
					sync();
				});
				frame.open();
			});
		});
	JS);
});

add_action('save_post_recipe', function (int $post_id) {
	if (!isset($_POST['foodies_gallery_nonce'])
		|| !wp_verify_nonce($_POST['foodies_gallery_nonce'], 'foodies_gallery')
		|| (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
		|| wp_is_post_revision($post_id)
		|| !current_user_can('edit_post', $post_id)) {
		return;
	}
	$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) wp_unslash($_POST['foodies_gallery'] ?? ''))))));
	if ($ids) {
		// Strings, as ACF saved them: a:1:{i:0;s:5:"18112";}
		update_post_meta($post_id, FOODIES_GALLERY_KEY, array_map('strval', $ids));
	} else {
		delete_post_meta($post_id, FOODIES_GALLERY_KEY);
	}
});

add_action('save_post_recipe', function (int $post_id) {
	if (!isset($_POST['foodies_history_nonce'])
		|| !wp_verify_nonce($_POST['foodies_history_nonce'], 'foodies_history')
		|| (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
		|| wp_is_post_revision($post_id)
		|| !current_user_can('edit_post', $post_id)) {
		return;
	}
	// As for the post content: HTML is filtered unless the user may post any.
	$history = trim(wp_unslash((string) ($_POST['foodies_history'] ?? '')));
	if (!current_user_can('unfiltered_html')) {
		$history = wp_kses_post($history);
	}
	delete_post_meta($post_id, FOODIES_HISTORY_CARBON_KEY);
	if ($history !== '') {
		update_post_meta($post_id, FOODIES_HISTORY_KEY, wp_slash($history));
	} else {
		delete_post_meta($post_id, FOODIES_HISTORY_KEY);
	}
});
