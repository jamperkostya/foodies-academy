<?php
/**
 * Settings of wp.dev.foodies.academy that Docker passes as environment variables.
 *
 * Copy next to wp-config.php as wp-config-foodies.php, fill in the secret and load it at the top
 * of wp-config.php (deploy/hestia/README.md, step 6):
 *
 *     sed -i "1a require_once __DIR__ . '/wp-config-foodies.php';" wp-config.php
 */

// The database keeps the production address (foodies.academy); the admin works on this one.
define('WP_HOME', 'https://wp.dev.foodies.academy');
define('WP_SITEURL', 'https://wp.dev.foodies.academy');
define('WP_ENVIRONMENT_TYPE', 'staging');

// mu-plugins read these with getenv() (foodies-users.php, foodies-rebuild.php). PHP-FPM
// doesn't pass the shell environment, so they are set here.
// The key is the same as FOODIES_API_SECRET in Laravel's .env: openssl rand -hex 32
putenv('FOODIES_API_SECRET=');
// WordPress reports content changes here, so dev.foodies.academy gets rebuilt.
putenv('FOODIES_LARAVEL_URL=https://api.dev.foodies.academy');

// Code comes from git and install-plugins.sh (pinned versions): no editing or auto-updates in the admin.
define('DISALLOW_FILE_EDIT', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
