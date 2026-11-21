#!/bin/bash
# Turns on the English version on a server, after the WordPress and Laravel code of this release are
# deployed: fixes the Russian recipes' ingredients, loads the translations, the English forms and
# interface strings, trashes the old theme's pages and rebuilds the site. Safe to re-run: every step
# skips what is done. Run as the site's user, after a backup of the database (README.md):
#   jamper@server:  bash setup-translations.sh dev
#   root@server:    sudo -u foodies bash /home/foodies/setup-translations.sh prod
set -euo pipefail

case "${1:-}" in
	dev) USER_NAME=jamper; WP_PATH=/home/jamper/web/wp.dev.foodies.academy/public_html; API_PATH=/home/jamper/web/api.dev.foodies.academy/public_html ;;
	prod) USER_NAME=foodies; WP_PATH=/home/foodies/web/wp.foodies.academy/public_html; API_PATH=/home/foodies/web/api.foodies.academy/public_html ;;
	*) echo "usage: bash setup-translations.sh dev|prod"; exit 1 ;;
esac
# Files written by another user (storage/, caches) would break the site.
[ "$(id -un)" = "$USER_NAME" ] || { echo "run it as $USER_NAME"; exit 1; }

PHP="${PHP_BIN:-php8.3}"
wp() { $PHP "$HOME/bin/wp" --path="$WP_PATH" "$@"; }
artisan() { (cd "$API_PATH" && $PHP artisan "$@"); }
TRANSLATIONS="$API_PATH/database/content/translations"

step() { echo; echo "== $*"; }

step "Code of this release"
wp plugin is-active polylang || { echo "Polylang is not active: run Deploy WordPress code first"; exit 1; }
artisan list --raw | grep -q '^translations:sync-counters' || { echo "Laravel is older: run Deploy Laravel first"; exit 1; }
wp core version

step "Typos in amounts and units of Russian recipes"
artisan recipes:fix-ingredients

step "Languages and translations"
wp foodies languages
wp foodies import-term-translations "$TRANSLATIONS/terms.en.json"
wp foodies import-recipe-translations "$TRANSLATIONS/recipes.en.json"
# Imported translations are drafts: published where the Russian recipe is.
wp eval '$n = 0; foreach (get_posts(["post_type" => "recipe", "lang" => "en", "post_status" => "draft", "numberposts" => -1, "fields" => "ids"]) as $id) { $ru = pll_get_post($id, "ru"); if ($ru && get_post_status($ru) === "publish") { wp_update_post(["ID" => $id, "post_status" => "publish"]); $n++; } } echo "Published $n translations.\n";'
wp foodies import-strings

step "English sign-up forms"
forms=$(wp foodies copy-forms 28089 77 --lang=en)
echo "$forms"
# "Subcribe (28089) → 29539", or "… (28089): copy 29539 exists." on a re-run.
form_id() { echo "$forms" | grep "($1)" | grep -oE '[0-9]+( exists\.)?$' | grep -oE '^[0-9]+' || true; }
subscribe=$(form_id 28089)
book=$(form_id 77)
[ -n "$subscribe" ] && [ -n "$book" ] || { echo "Form IDs not found in the output above"; exit 1; }
for pair in "CF7_SUBSCRIBE_FORM_ID_EN=$subscribe" "CF7_BOOK_FORM_ID_EN=$book"; do
	key="${pair%%=*}"
	if grep -q "^$key=" "$API_PATH/.env"; then
		sed -i "s/^$key=.*/$pair/" "$API_PATH/.env"
	else
		echo "$pair" >> "$API_PATH/.env"
	fi
	echo "$pair → .env"
done

step "Pages of the old theme and WooCommerce, to the trash"
# id:slug, so a page that is something else on this database stays.
for page in 31:faq 33:blog 35:video 37:kak-sdelat 40:shop 44:receipes 46:school 401:registration 788:kuhni-mira 20241:cart 20242:checkout 20243:my-account; do
	id="${page%%:*}"
	if [ "$(wp post get "$id" --field=post_name 2>/dev/null)" = "${page#*:}" ] && [ "$(wp post get "$id" --field=post_status)" != trash ]; then
		wp post delete "$id"
	fi
done

step "Laravel: config, counters, caches"
artisan optimize
artisan translations:sync-counters
artisan cache:clear
artisan cache:clear seo

step "Site build"
artisan site:rebuild --force
echo
echo "Done. Check https://$([ "$1" = dev ] && echo dev.)foodies.academy/en after the build (~15 minutes)."
