#!/bin/bash
# Brings WordPress and the plugins foodies.academy relies on to their pinned versions and activates
# the plugins. Safe to re-run: what is already at its version is only activated. A version changed
# here reaches Docker and the servers on the next start / deploy. Exits with an error if a plugin
# ends up inactive.
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
# Is version $1 older than $2? Only upgrades: rolling back a newer one (updated from the admin)
# could break the data its updates already changed.
older() { [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" = "$1" ]; }

# Wait for the entrypoint to write wp-config.php and for the database.
for _ in $(seq 1 60); do
	[ -f wp-config.php ] && wp db check --quiet >/dev/null 2>&1 && break
	sleep 2
done
if ! wp core is-installed >/dev/null 2>&1; then
	echo "[plugins] WordPress is not installed in this database, skipping"
	exit 0
fi

# The core: the Docker image (wordpress/Dockerfile) has the same version, but its files live in a
# volume that keeps the old ones, and the servers have their own copy.
WP_VERSION="7.1.5"
current=$(wp core version)
if older "$current" "$WP_VERSION"; then
	echo "[core] $current → $WP_VERSION"
	wp core update --version="$WP_VERSION" && wp core update-db || { echo "[core] update failed"; exit 1; }
elif [ "$current" != "$WP_VERSION" ]; then
	echo "[core] $current is newer than the pinned $WP_VERSION: pin it here"
fi

# slug=version, or slug=zip URL for plugins no longer listed on wordpress.org.
PLUGINS=(
	"contact-form-7=6.2.5"
	"contact-form-cfdb7=1.4.2"
	"cyr2lat=7.3.0"
	# Translations; set up once with wp foodies languages (mu-plugin foodies-translations.php).
	"polylang=3.9.2"
	"posts-like-dislike=1.1.6"
	"post-views-counter=1.8.2"
	"search-meter=2.14.1"
	"svg-support=2.6.4"
	"wptelegram=4.2.16"
	# Closed on wordpress.org, the last release (3.13.0) is still downloadable.
	"wp-ultimate-recipe=https://downloads.wordpress.org/plugin/wp-ultimate-recipe.zip"
	"wordpress-seo=28.9"
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
	elif [[ "$source" != http* ]]; then
		installed=$(wp plugin get "$slug" --field=version)
		if older "$installed" "$source"; then
			echo "[plugins] $slug $installed → $source"
			wp plugin install "$slug" --version="$source" --force || echo "[plugins] failed to update $slug"
		elif [ "$installed" != "$source" ]; then
			echo "[plugins] $slug $installed is newer than the pinned $source: pin it here"
		fi
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
