#!/usr/bin/env bash
# Removes what the live foodies.academy database collected over the years and the site doesn't
# need: spam comments, spam accounts, tables of removed plugins, old revisions; and brings every
# table to utf8mb4_unicode_ci. The same cleanup was done by hand on foodies_dev (November 2026);
# this repeats it on a fresh copy.
#
# Run as root@server on the copy, after "php artisan site:prepare-db", with a backup made:
#   bash clean-site-db.sh jamper_foodies_prod_site
set -euo pipefail

DB=${1:?database name}
P=${PREFIX:-artit_}
SQL() { ${MYSQL:-mariadb} "$DB" "${@:2}" -e "$1"; }

# 1. Pending comments up to the last one reviewed on foodies_dev were all spam, except two
#    real reviews. Newer pending comments are only listed: look at them in the admin.
REVIEWED=50583
SQL "
DELETE m FROM ${P}commentmeta m JOIN ${P}comments c ON c.comment_ID = m.comment_id
  WHERE c.comment_approved = '0' AND c.comment_ID <= $REVIEWED AND c.comment_ID NOT IN (15780, 15762);
DELETE FROM ${P}comments WHERE comment_approved = '0' AND comment_ID <= $REVIEWED AND comment_ID NOT IN (15780, 15762);
SELECT ROW_COUNT() AS spam_comments_deleted;
SELECT comment_ID, comment_post_ID, comment_author, LEFT(comment_content, 80) AS newer_pending_to_check
  FROM ${P}comments WHERE comment_approved = '0' AND comment_ID > $REVIEWED;"

# Comments Akismet or a moderator marked as spam: never shown, kept only for Akismet's history.
SQL "
DELETE m FROM ${P}commentmeta m JOIN ${P}comments c ON c.comment_ID = m.comment_id WHERE c.comment_approved = 'spam';
DELETE FROM ${P}comments WHERE comment_approved = 'spam';
SELECT ROW_COUNT() AS marked_spam_deleted;"

# 2. Spam accounts: subscribers who never posted, commented, logged in to the new site or saved
#    anything, and whose name, address, e-mail or bio look like a bot's.
SPAM="(u.user_url <> ''
  OR u.display_name REGEXP 'https?://|www\\\\.|\\\\.(com|ru|net|org|xyz|top|info|biz|io|site|online|shop|store)'
  OR u.user_login REGEXP 'https?|www|\\\\.(com|ru|net)'
  OR b.ID IS NOT NULL
  OR u.user_login REGEXP 'telegram|sberbank|tinkoff|tink|vtb|rub|bitcoin|crypto|casino|bonus|invest|@|usd|bingx|\\\\.(blog|dev|page|site|app)'
  OR u.display_name REGEXP 'telegram|SBERBANK|TINK|VTB|RUB|USD|BAM [0-9]'
  OR BINARY u.user_login REGEXP '^[A-Za-z]{8,}\$' AND BINARY u.user_login REGEXP '[a-z][A-Z][a-z]?[A-Z]'
  OR u.user_login REGEXP '^[0-9 ]+\$'
  OR SUBSTRING_INDEX(u.user_email, '@', 1) REGEXP '([^.]+\\\\.){3,}'
  OR u.user_email NOT REGEXP '@(gmail|googlemail|yahoo|hotmail|outlook|live|msn|icloud|me|mac|aol|mail|yandex|ya|bk|list|inbox|rambler|ukr|i|meta|gmx|web|seznam|centrum|email|post|tut|onet|wp|o2|interia|libero|orange|free|laposte|t-online|protonmail|proton|zoho|qq|163|126|naver|daum|abv|freemail|inbox\\\\.lv|bigmir)\\\\.[a-z.]+\$'
  OR u.user_email REGEXP '^[a-z]{10}@')"
# Who left a trace and whose bio has links go to temporary tables first: as EXISTS subqueries inside
# the ORs, MariaDB took hours on the 172k accounts of an uncleaned copy.
SQL "
CREATE TEMPORARY TABLE active_users (ID BIGINT UNSIGNED PRIMARY KEY)
  SELECT post_author AS ID FROM ${P}posts WHERE post_author > 0
  UNION SELECT user_id FROM ${P}comments WHERE user_id > 0
  UNION SELECT user_id FROM ${P}usermeta
    WHERE meta_key IN ('session_tokens', 'foodies_favorite', 'foodies_follow_author', '${P}user_avatar');
CREATE TEMPORARY TABLE bio_links (ID BIGINT UNSIGNED PRIMARY KEY)
  SELECT DISTINCT user_id AS ID FROM ${P}usermeta
    WHERE meta_key = 'description' AND meta_value REGEXP 'https?://|www\\\\.|<a ';
CREATE TEMPORARY TABLE spam_users (ID BIGINT UNSIGNED PRIMARY KEY)
  SELECT DISTINCT u.ID FROM ${P}users u
  JOIN ${P}usermeta r ON r.user_id = u.ID AND r.meta_key = '${P}capabilities' AND r.meta_value LIKE '%subscriber%'
  LEFT JOIN active_users a ON a.ID = u.ID
  LEFT JOIN bio_links b ON b.ID = u.ID
  WHERE a.ID IS NULL AND $SPAM;
SELECT COUNT(*) AS spam_users FROM spam_users;
DELETE y FROM ${P}yoast_indexable y JOIN spam_users d ON d.ID = y.object_id AND y.object_type = 'user';
DELETE h FROM ${P}yoast_indexable_hierarchy h LEFT JOIN ${P}yoast_indexable y ON y.id = h.indexable_id WHERE y.id IS NULL;
DELETE u FROM ${P}users u JOIN spam_users d ON d.ID = u.ID;
DELETE m FROM ${P}usermeta m LEFT JOIN ${P}users u ON u.ID = m.user_id WHERE u.ID IS NULL;"

# 3. Tables that neither WordPress with its current plugins, nor Laravel, nor Astro use
#    (icl_translations only by site:prepare-db, which has run by now).
TABLES="
  artit_actionscheduler_actions artit_actionscheduler_claims artit_actionscheduler_groups
  artit_actionscheduler_logs artit_downloadstats artit_downloadtracking artit_fusewp_queue_jobs
  artit_fusewp_sync artit_fusewp_sync_log artit_icl_content_status artit_icl_core_status
  artit_icl_flags artit_icl_languages artit_icl_languages_translations artit_icl_locale_map
  artit_icl_message_status artit_icl_node artit_icl_reminders artit_icl_strings
  artit_icl_string_positions artit_icl_string_status artit_icl_string_translations
  artit_icl_translate artit_icl_translate_job artit_icl_translation_batches
  artit_icl_translation_downloads artit_icl_translation_status artit_icl_translations artit_kcc_clicks
  artit_post_views_summary artit_ppress_coupons artit_ppress_customers artit_ppress_forms
  artit_ppress_formsmeta artit_ppress_meta_data artit_ppress_ordermeta artit_ppress_orders
  artit_ppress_plans artit_ppress_sessions artit_ppress_subscriptions artit_relevanssi
  artit_relevanssi_log artit_relevanssi_stopwords artit_wc_admin_notes
  artit_wc_admin_note_actions artit_wc_category_lookup artit_wc_customer_lookup
  artit_wc_download_log artit_wc_order_coupon_lookup artit_wc_order_product_lookup
  artit_wc_order_stats artit_wc_order_tax_lookup artit_wc_product_meta_lookup
  artit_wc_reserved_stock artit_wc_tax_rate_classes artit_wc_webhooks artit_woocommerce_api_keys
  artit_woocommerce_attribute_taxonomies artit_woocommerce_downloadable_product_permissions
  artit_woocommerce_log artit_woocommerce_order_itemmeta artit_woocommerce_order_items
  artit_woocommerce_payment_tokenmeta artit_woocommerce_payment_tokens artit_woocommerce_sessions
  artit_woocommerce_shipping_zones artit_woocommerce_shipping_zone_locations
  artit_woocommerce_shipping_zone_methods artit_woocommerce_tax_rates
  artit_woocommerce_tax_rate_locations wc_ukr_shipping_np_areas wc_ukr_shipping_np_cities
  wc_ukr_shipping_np_warehouses"
SQL "DROP TABLE IF EXISTS $(for t in $TABLES; do printf '`%s`,' "$t"; done | sed 's/,$//');"

# 4. Revisions beyond the 10 newest of each post (the limit of mu-plugins/foodies-revisions.php,
#    which WordPress applies only when a post is saved again). WP Ultimate Recipe copies every
#    recipe field into a revision, so most of postmeta was old revisions.
SQL "
CREATE TEMPORARY TABLE old_revisions (ID BIGINT UNSIGNED PRIMARY KEY)
  SELECT ID FROM (SELECT ID, ROW_NUMBER() OVER (PARTITION BY post_parent ORDER BY post_date DESC, ID DESC) AS n
    FROM ${P}posts WHERE post_type = 'revision') r WHERE n > 10;
SELECT COUNT(*) AS old_revisions FROM old_revisions;
DELETE m FROM ${P}postmeta m JOIN old_revisions o ON o.ID = m.post_id;
DELETE p FROM ${P}posts p JOIN old_revisions o ON o.ID = p.ID;"

# 5. One collation everywhere: plugins created some tables as utf8mb3 (no emoji in a search
#    query) or utf8mb4_unicode_520_ci, which "Illegal mix of collations" errors come from.
#    The database's own default first: Hestia creates databases as utf8mb3, and a plugin's new
#    table without a charset of its own takes it.
SQL "ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for t in $(SQL "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()
  AND table_type = 'BASE TABLE' AND table_collation <> 'utf8mb4_unicode_ci'" -N); do
	echo "utf8mb4_unicode_ci: $t"
	SQL "ALTER TABLE \`$t\` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
done

SQL "OPTIMIZE TABLE ${P}comments, ${P}commentmeta, ${P}users, ${P}usermeta, ${P}posts, ${P}postmeta;" > /dev/null
echo "Done: $(SQL "SELECT COUNT(*) FROM ${P}users" -N) users, $(SQL "SELECT COUNT(*) FROM ${P}comments WHERE comment_approved = '1'" -N) approved comments."
