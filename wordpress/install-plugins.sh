#!/bin/bash
# Installs and activates the plugins foodies.academy relies on. Safe to re-run:
# plugins already present are only activated. Versions are pinned, like everything else.
set -u
cd /var/www/html

wp() { command wp --allow-root --path=/var/www/html "$@"; }

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
			http*) wp plugin install "$source" --quiet ;;
			*) wp plugin install "$slug" --version="$source" --quiet ;;
		esac || { echo "[plugins] failed to install $slug"; continue; }
	fi
	wp plugin is-active "$slug" || wp plugin activate "$slug" --quiet || echo "[plugins] failed to activate $slug"
done
echo "[plugins] done"
