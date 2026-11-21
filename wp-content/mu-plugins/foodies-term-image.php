<?php
/**
 * Plugin Name: Foodies term image
 * Description: "Изображение" of a dish type or cuisine (taxonomies course and cuisine): the picture of
 *              its card on the site (/categories, /kitchens). Kept where the old theme's Carbon
 *              Fields kept it, term meta _crb_category_thumb (an attachment ID), which Laravel reads;
 *              that plugin is gone, so this is the field in the admin now. Translations show the
 *              Russian term's picture.
 */

if (!defined('ABSPATH')) {
	exit;
}

const FOODIES_TERM_IMAGE_KEY = '_crb_category_thumb';
const FOODIES_TERM_IMAGE_TAXONOMIES = ['course', 'cuisine'];

function foodies_term_image_field(WP_Term $term): void
{
	if (function_exists('pll_get_term_language') && ($lang = pll_get_term_language($term->term_id)) && $lang !== 'ru') {
		echo '<tr class="form-field"><th scope="row">Изображение</th><td><p class="description">Картинка берётся у русской рубрики.</p></td></tr>';

		return;
	}
	$id = (int) get_term_meta($term->term_id, FOODIES_TERM_IMAGE_KEY, true) ?: (int) get_term_meta($term->term_id, 'image', true);
	$url = $id ? (string) wp_get_attachment_image_url($id, 'medium') : '';
	wp_nonce_field('foodies_term_image', 'foodies_term_image_nonce');
	?>
	<tr class="form-field">
		<th scope="row">Изображение</th>
		<td>
			<input type="hidden" name="foodies_term_image" id="foodies-term-image" value="<?php echo $id ?: ''; ?>">
			<img id="foodies-term-image-preview" src="<?php echo esc_url($url); ?>" alt="" style="display:block;max-width:202px;margin-bottom:8px"<?php echo $url ? '' : ' hidden'; ?>>
			<button type="button" class="button" id="foodies-term-image-choose">Выбрать картинку</button>
			<button type="button" class="button-link button-link-delete" id="foodies-term-image-remove"<?php echo $id ? '' : ' hidden'; ?>>Удалить</button>
			<p class="description">Карточка рубрики на сайте, 202×243 px.</p>
			<script>
			(function () {
				var input = document.getElementById('foodies-term-image');
				var preview = document.getElementById('foodies-term-image-preview');
				var remove = document.getElementById('foodies-term-image-remove');
				var frame;
				function set(id, url) {
					input.value = id;
					preview.src = url;
					preview.hidden = !id;
					remove.hidden = !id;
				}
				document.getElementById('foodies-term-image-choose').addEventListener('click', function () {
					frame = frame || wp.media({ title: 'Изображение рубрики', library: { type: 'image' }, button: { text: 'Выбрать' }, multiple: false });
					frame.off('select').on('select', function () {
						var image = frame.state().get('selection').first().toJSON();
						set(image.id, (image.sizes && image.sizes.medium || image).url);
					});
					frame.open();
				});
				remove.addEventListener('click', function () { set('', ''); });
			})();
			</script>
		</td>
	</tr>
	<?php
}

function foodies_term_image_save(int $term_id): void
{
	if (!isset($_POST['foodies_term_image_nonce'])
		|| !wp_verify_nonce($_POST['foodies_term_image_nonce'], 'foodies_term_image')
		|| !current_user_can('manage_categories')) {
		return;
	}
	$id = (int) ($_POST['foodies_term_image'] ?? 0);
	if ($id && wp_attachment_is_image($id)) {
		update_term_meta($term_id, FOODIES_TERM_IMAGE_KEY, (string) $id);
	} else {
		delete_term_meta($term_id, FOODIES_TERM_IMAGE_KEY);
		delete_term_meta($term_id, 'image');
	}
}

foreach (FOODIES_TERM_IMAGE_TAXONOMIES as $taxonomy) {
	add_action("{$taxonomy}_edit_form_fields", 'foodies_term_image_field');
	// Before edited_term (foodies-rebuild.php reports the change), so the build sees the picture.
	add_action("edit_{$taxonomy}", 'foodies_term_image_save');
}

add_action('admin_enqueue_scripts', function () {
	$screen = get_current_screen();
	if ($screen && $screen->base === 'term' && in_array($screen->taxonomy, FOODIES_TERM_IMAGE_TAXONOMIES, true)) {
		wp_enqueue_media();
	}
});
