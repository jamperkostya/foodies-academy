<?php
/**
 * Plugin Name: Foodies user profile
 * Description: The user's photo and bio on the WordPress profile screen. The photo is the attachment id
 *              in "{prefix}user_avatar", left by the old theme's avatar plugin (not installed any more)
 *              and read by Laravel for author cards; WordPress shows it instead of Gravatar everywhere
 *              in the admin. The bio ("description") gets the visual editor; the site shows it as HTML.
 */

if (!defined('ABSPATH')) {
	exit;
}

function foodies_avatar_key(): string
{
	global $wpdb;

	return $wpdb->get_blog_prefix() . 'user_avatar';
}

function foodies_avatar_user_id($id_or_email): int
{
	if ($id_or_email instanceof WP_User) {
		return $id_or_email->ID;
	}
	if ($id_or_email instanceof WP_Post) {
		return (int) $id_or_email->post_author;
	}
	if ($id_or_email instanceof WP_Comment) {
		return (int) $id_or_email->user_id;
	}
	if (is_numeric($id_or_email)) {
		return (int) $id_or_email;
	}
	$user = is_string($id_or_email) ? get_user_by('email', $id_or_email) : false;

	return $user ? $user->ID : 0;
}

add_filter('pre_get_avatar_data', function (array $args, $id_or_email) {
	// The old plugin set its own "wp_user_avatar" default, which Gravatar doesn't know.
	if (($args['default'] ?? '') === 'wp_user_avatar') {
		$args['default'] = 'mystery';
	}
	$user_id = foodies_avatar_user_id($id_or_email);
	$attachment = $user_id ? (int) get_user_meta($user_id, foodies_avatar_key(), true) : 0;
	$image = $attachment ? wp_get_attachment_image_src($attachment, [$args['size'], $args['size']]) : false;
	if ($image) {
		$args['url'] = $image[0];
		$args['found_avatar'] = true;
	}

	return $args;
}, 10, 2);

add_action('admin_enqueue_scripts', function (string $hook) {
	if (in_array($hook, ['profile.php', 'user-edit.php'], true) && current_user_can('upload_files')) {
		wp_enqueue_media();
	}
});

// Photo: "Choose" / "Remove" under the picture instead of the link to Gravatar.
add_filter('user_profile_picture_description', function (string $description, WP_User $user) {
	if (!current_user_can('upload_files')) {
		return $description;
	}
	$attachment = (int) get_user_meta($user->ID, foodies_avatar_key(), true);
	ob_start(); ?>
	<input type="hidden" name="foodies_avatar" id="foodies-avatar" value="<?php echo $attachment ?: ''; ?>">
	<button type="button" class="button" id="foodies-avatar-choose">Выбрать фото</button>
	<button type="button" class="button-link button-link-delete" id="foodies-avatar-remove"<?php echo $attachment ? '' : ' hidden'; ?>>Удалить фото</button>
	<br>Фото показывается в карточке автора на сайте.
	<script>
	(function () {
		var input = document.getElementById('foodies-avatar');
		var remove = document.getElementById('foodies-avatar-remove');
		var img = document.querySelector('.user-profile-picture img');
		var initial = img ? img.src : '';
		var frame;
		function set(id, url) {
			input.value = id;
			remove.hidden = !id;
			if (img) { img.src = url; img.removeAttribute('srcset'); }
		}
		document.getElementById('foodies-avatar-choose').addEventListener('click', function () {
			frame = frame || wp.media({ title: 'Фото профиля', library: { type: 'image' }, button: { text: 'Выбрать' }, multiple: false });
			frame.off('select').on('select', function () {
				var image = frame.state().get('selection').first().toJSON();
				set(image.id, (image.sizes && image.sizes.thumbnail || image).url);
			});
			frame.open();
		});
		// Until saved, the old picture is the best preview of "no photo" (Gravatar or the placeholder).
		remove.addEventListener('click', function () { set('', initial); });
	})();
	</script>
	<?php

	return ob_get_clean();
}, 10, 2);

// Bio: the visual editor in place of the plain textarea. Rendered at the end of the form, then
// moved into the "Биография" row before TinyMCE starts (it starts in the footer).
$foodies_bio_editor = function (WP_User $user) {
	echo '<div id="foodies-bio" hidden>';
	wp_editor((string) get_user_meta($user->ID, 'description', true), 'foodies_description', [
		'textarea_name' => 'description',
		'textarea_rows' => 10,
		'media_buttons' => false,
		'teeny' => true,
	]);
	echo '</div>';
	?>
	<script>
	(function () {
		var editor = document.getElementById('foodies-bio');
		var textarea = document.getElementById('description');
		if (!textarea) { return; }
		textarea.replaceWith.apply(textarea, Array.prototype.slice.call(editor.childNodes));
		editor.remove();
	})();
	</script>
	<?php
};
add_action('show_user_profile', $foodies_bio_editor);
add_action('edit_user_profile', $foodies_bio_editor);

// The default kses for bios keeps only inline tags; the editor also makes paragraphs and lists.
remove_filter('pre_user_description', 'wp_filter_kses');
add_filter('pre_user_description', 'wp_filter_post_kses');

$foodies_save_avatar = function (int $user_id) {
	// The profile form's own nonce is checked by WordPress before this action.
	if (!isset($_POST['foodies_avatar']) || !current_user_can('edit_user', $user_id) || !current_user_can('upload_files')) {
		return;
	}
	$attachment = absint($_POST['foodies_avatar']);
	if ($attachment && wp_attachment_is_image($attachment)) {
		update_user_meta($user_id, foodies_avatar_key(), $attachment);
	} elseif (!$attachment) {
		delete_user_meta($user_id, foodies_avatar_key());
	}
};
add_action('personal_options_update', $foodies_save_avatar);
add_action('edit_user_profile_update', $foodies_save_avatar);
