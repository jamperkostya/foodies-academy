#!/bin/bash
# Installs and activates the plugins foodies.academy relies on. Safe to re-run:
# plugins already present are only activated. Versions are pinned, like everything else.
# Exits with an error if any of them ends up inactive.
#
# Docker runs it as root with the defaults; on Hestia the deploy runs it as the site user:
#   WP_PATH=/home/jamper/web/wp.dev.foodies.academy/public_html WP_CLI="php8.3 $HOME/bin/wp" bash install-plugins.sh
set -u
WP_PATH="${WP_PATH:-/var/www/html}"
WP_CLI="${WP_CLI:-wp}"
ALLOW_ROOT=""
[ "$(id -u)" = 0 ] && ALLOW_ROOT="--allow-root"
cd "$WP_PATH"

wp() { command $WP_CLI $ALLOW_ROOT --path="$WP_PATH" "$@"; }

# Wait for the entrypoint to write wp-config.php and for the database.
for _ in $(seq 1 60); do
	[ -f wp-config.php ] && wp db check --quiet >/dev/null 2>&1 && break
	sleep 2
done
if ! wp core is-installed >/dev/null 2>&1; then
	echo "[plugins] WordPress is not installed in this database, skipping"
	exit 0
fi

# slug=version, or slug=zip URL for plugins no longer listed on wordpress.org.
PLUGINS=(
	# 6.2+ needs WordPress 7.1.
	"contact-form-7=6.1.7"
	"contact-form-cfdb7=1.4.2"
	"cyr2lat=7.2.0"
	"posts-like-dislike=1.1.6"
	"post-views-counter=1.8.2"
	"search-meter=2.14.1"
	"svg-support=2.6.3"
	"wptelegram=4.2.15"
	# Closed on wordpress.org, the last release (3.13.0) is still downloadable.
	"wp-ultimate-recipe=https://downloads.wordpress.org/plugin/wp-ultimate-recipe.zip"
	"wordpress-seo=28.8"
)

for entry in "${PLUGINS[@]}"; do
	slug="${entry%%=*}"
	source="${entry#*=}"
	if ! wp plugin is-installed "$slug"; then
		echo "[plugins] installing $slug"
		case "$source" in
			http*) wp plugin install "$source" ;;
			*) wp plugin install "$slug" --version="$source" ;;
		esac || echo "[plugins] failed to install $slug"
	fi
	wp plugin is-active "$slug" 2>/dev/null || wp plugin activate "$slug" --quiet || echo "[plugins] failed to activate $slug"
done

# A plugin that didn't install or activate (Contact Form 7 once, so sign-ups broke) fails the
# deploy instead of leaving it green.
missing=()
# WP-CLI's own error is printed: "not active" alone hid a check that failed for another reason.
for entry in "${PLUGINS[@]}"; do
	slug="${entry%%=*}"
	if ! output=$(wp plugin is-active "$slug" 2>&1); then
		missing+=("$slug")
		[ -n "$output" ] && echo "[plugins] $slug: $output"
	fi
done
if [ ${#missing[@]} -gt 0 ]; then
	echo "[plugins] not active: ${missing[*]}"
	exit 1
fi
echo "[plugins] done"
