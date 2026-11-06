<?php
/**
 * Plugin Name: Foodies frontend
 * Description: WordPress is only the admin: its own pages (wp.foodies.academy/recipe/x) send visitors
 *              and search engines to the same address on the site, so the content isn't indexed twice.
 *              Needs FOODIES_SITE_URL in the environment; without it (local Docker) nothing changes.
 */

if (!defined('ABSPATH')) {
	exit;
}

// Fires for front-end pages only: the admin, REST API, log-in, cron and AJAX keep working.
add_action('template_redirect', function () {
	$site = rtrim((string) getenv('FOODIES_SITE_URL'), '/');
	// Editors still see the preview of an unpublished post.
	if ($site === '' || is_preview()) {
		return;
	}
	$path = untrailingslashit((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
	wp_redirect($site . $path, 301, 'Foodies');
	exit;
}, 0);
