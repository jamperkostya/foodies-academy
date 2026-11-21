<?php
/**
 * Plugin Name: Foodies translations
 * Description: Languages of the site in Polylang and the import of recipe translations (WP-CLI).
 *              A translation is its own recipe post linked to the Russian one; what isn't text
 *              (amounts, units, photos, video, terms) the site takes from the Russian recipe.
 */

if (!defined('ABSPATH')) {
	exit;
}

/** Russian first: the default language, the one of all content made before translations. */
const FOODIES_LANGUAGES = [
	['locale' => 'ru_RU', 'slug' => 'ru', 'name' => 'Русский', 'flag' => 'ru', 'term_group' => 0],
	['locale' => 'en_US', 'slug' => 'en', 'name' => 'English', 'flag' => 'us', 'term_group' => 1],
];

// Interface strings in the admin: Languages → Translations, group "Foodies".
add_action('init', function () {
	if (is_admin() && function_exists('pll_register_string')) {
		foreach (foodies_strings() as $string) {
			pll_register_string(mb_substr($string['ru'], 0, 60), $string['ru'], 'Foodies', mb_strlen($string['ru']) > 80);
		}
	}
});

/**
 * The Russian recipe is the original: a recipe in another language is its translation (Polylang's
 * "+" next to the Russian one). One without it can't be published: it stays a draft.
 * Saving a translation in the editor makes WP Ultimate Recipe create ingredient terms from its
 * names; they are taken off and deleted: the site shows the original's ingredients, photos and terms.
 */
function foodies_translation_saved(int $id, WP_Post $post): void
{
	if ($post->post_type !== 'recipe' || wp_is_post_revision($id) || !function_exists('pll_get_post_language')) {
		return;
	}
	$lang = pll_get_post_language($id);
	if (!$lang || $lang === 'ru') {
		return;
	}
	if (!pll_get_post($id, 'ru') && $post->post_status === 'publish') {
		remove_action('save_post_recipe', 'foodies_translation_saved', 30);
		wp_update_post(['ID' => $id, 'post_status' => 'draft']);
		set_transient('foodies_translation_notice_' . get_current_user_id(), 1, 60);
	}
	$created = wp_get_object_terms($id, 'ingredient', ['fields' => 'ids']);
	if ($created && !is_wp_error($created)) {
		wp_set_object_terms($id, [], 'ingredient');
		foreach ($created as $term_id) {
			// Terms of this language nobody translated from Russian: the ones just made.
			if (pll_get_term_language($term_id) !== 'ru' && !pll_get_term($term_id, 'ru')) {
				wp_delete_term($term_id, 'ingredient');
			}
		}
	}
}

add_action('save_post_recipe', 'foodies_translation_saved', 30, 2);
// Polylang sets the language of a new post after save_post; it says so with pll_save_post.
add_action('pll_save_post', fn ($id, $post) => foodies_translation_saved((int) $id, $post), 30, 2);

add_action('admin_notices', function () {
	$key = 'foodies_translation_notice_' . get_current_user_id();
	if (get_transient($key)) {
		delete_transient($key);
		echo '<div class="notice notice-error"><p>Английский рецепт — перевод русского: создайте его кнопкой «+» у русского рецепта (колонка языка в списке рецептов). Без русского рецепта он сохранён как черновик.</p></div>';
	}
});

if (!defined('WP_CLI') || !WP_CLI) {
	return;
}

/**
 * Creates the languages and sets Polylang up for the site: translated post types and taxonomies,
 * one media library for all languages, nothing copied between translations. Content without a
 * language becomes Russian. Safe to re-run.
 *
 *     wp foodies languages
 */
WP_CLI::add_command('foodies languages', function () {
	if (!function_exists('PLL')) {
		WP_CLI::error('Polylang is not active.');
	}
	$model = PLL()->model;
	foreach (FOODIES_LANGUAGES as $language) {
		if ($model->languages->get($language['slug'])) {
			continue;
		}
		$added = $model->languages->add($language + ['no_default_cat' => true]);
		if (is_wp_error($added)) {
			WP_CLI::error("{$language['slug']}: " . $added->get_error_message());
		}
		WP_CLI::log("Added the language {$language['slug']}.");
	}

	$options = PLL()->options;
	$options['default_lang'] = FOODIES_LANGUAGES[0]['slug'];
	// Posts and pages are translated by Polylang itself.
	$options['post_types'] = array_values(array_filter(['recipe', 'video', 'lifehack', 'news'], 'post_type_exists'));
	$options['taxonomies'] = array_values(array_filter(['course', 'cuisine', 'ingredient', 'lifehack_category', 'news_category'], 'taxonomy_exists'));
	// Photos are the same in every language: one attachment, not a copy per language.
	$options['media_support'] = false;
	// Copied fields would overwrite a translation with the Russian text; the site reads what is
	// shared from the Russian recipe itself.
	$options['sync'] = [];
	// The site is static: WordPress pages never choose a language for a visitor.
	$options['browser'] = false;
	$options->save();

	$model->set_language_in_mass();
	WP_CLI::success('Languages: ' . implode(', ', wp_list_pluck(FOODIES_LANGUAGES, 'slug')) . '. Content without a language is ' . FOODIES_LANGUAGES[0]['slug'] . '.');
});

/**
 * Imports recipe translations prepared by Laravel (php artisan recipes:prepare-translations):
 * creates the translation of each recipe, or updates the draft imported before. A new translation
 * is a draft: the site shows it once published; a published one is kept without --force. A recipe
 * whose slug isn't the one in the file (another database) is skipped.
 *
 * Ingredients keep the Russian recipe's amounts, units and terms with the translated names; a
 * recipe whose ingredients didn't line up gets the Russian ones to translate in the admin.
 *
 *     wp foodies import-recipe-translations recipes.en.json [--dry-run] [--force]
 */
WP_CLI::add_command('foodies import-recipe-translations', function (array $args, array $assoc) {
	if (!function_exists('pll_insert_post') || !PLL()->model->languages->get('ru')) {
		WP_CLI::error('Polylang is not set up: run wp foodies languages first.');
	}
	$data = json_decode((string) @file_get_contents($args[0] ?? ''), true);
	$lang = (string) ($data['lang'] ?? '');
	if (!is_array($data['recipes'] ?? null) || !PLL()->model->languages->get($lang)) {
		WP_CLI::error('Not a file of translations into a site language: ' . ($args[0] ?? ''));
	}
	$dry = !empty($assoc['dry-run']);
	$counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

	foreach ($data['recipes'] as $t) {
		$source = get_post((int) $t['source_id']);
		if (!$source || $source->post_type !== 'recipe' || $source->post_name !== $t['source_slug']) {
			WP_CLI::warning("Recipe {$t['source_id']} ({$t['source_slug']}) not found, skipped «{$t['title']}».");
			$counts['skipped']++;
			continue;
		}
		if (pll_get_post_language($source->ID) !== 'ru') {
			$dry || pll_set_post_language($source->ID, 'ru');
		}
		$id = pll_get_post($source->ID, $lang) ?: 0;
		// A published translation may have been edited since: only replaced when asked to.
		if ($id && get_post_status($id) === 'publish' && empty($assoc['force'])) {
			WP_CLI::log("«{$t['title']}» is published, kept (--force replaces it).");
			$counts['skipped']++;
			continue;
		}
		$counts[$id ? 'updated' : 'created']++;
		if ($dry) {
			continue;
		}

		$post = [
			'post_type' => 'recipe',
			'post_title' => $t['title'],
			'post_name' => sanitize_title($t['title']),
			'post_author' => $source->post_author,
			'comment_status' => $source->comment_status,
		];
		if ($id) {
			$id = pll_update_post(['ID' => $id] + $post);
		} else {
			$id = pll_insert_post($post + ['post_status' => 'draft'], $lang);
		}
		if (is_wp_error($id) || !$id) {
			WP_CLI::warning("«{$t['title']}»: " . (is_wp_error($id) ? $id->get_error_message() : 'not saved'));
			continue;
		}
		pll_save_post_translations([$lang => $id] + pll_get_post_translations($source->ID));

		update_post_meta($id, 'recipe_title', $t['title']);
		// update_post_meta() unslashes: backslashes in the texts would be lost.
		update_post_meta($id, 'recipe_ingredients', wp_slash(foodies_translated_ingredients($source->ID, $t['ingredients'])));
		update_post_meta($id, 'recipe_instructions', wp_slash(array_map(fn ($step) => ['description' => $step['text'], 'group' => $step['group'], 'image' => ''], $t['instructions'])));
		// The cover in the admin's list of recipes; the site shows the Russian recipe's photos.
		$cover = get_post_thumbnail_id($source->ID);
		$cover ? set_post_thumbnail($id, $cover) : delete_post_thumbnail($id);
		// What the translation was made from: a later change of the Russian recipe makes it outdated.
		update_post_meta($id, 'foodies_translation', wp_slash(wp_json_encode([
			'source_hash' => foodies_translation_source_hash($source->ID),
			'origin' => 'import',
			'imported' => gmdate('c'),
			'warnings' => $t['warnings'],
		], JSON_UNESCAPED_UNICODE)));
	}

	if (!$dry) {
		// Yoast's templates are Russian: new translations get English ones.
		foodies_seo_templates($lang, array_values(array_filter(array_map(fn ($t) => pll_get_post((int) $t['source_id'], $lang), $data['recipes']))), [0]);
	}
	WP_CLI::success(($dry ? 'Dry run: ' : '') . "{$counts['created']} created, {$counts['updated']} updated, {$counts['skipped']} skipped.");
});

/** The Russian recipe's ingredients with translated names, notes, groups and unit labels. */
function foodies_translated_ingredients(int $source_id, ?array $translated): array
{
	$ingredients = array_values(array_filter((array) get_post_meta($source_id, 'recipe_ingredients', true), fn ($i) => is_array($i) && trim($i['ingredient'] ?? '') !== ''));
	if ($translated === null || count($translated) !== count($ingredients)) {
		return $ingredients;
	}
	foreach ($ingredients as $k => $ingredient) {
		$ingredients[$k] = ['ingredient' => $translated[$k]['name'], 'notes' => $translated[$k]['notes'], 'group' => $translated[$k]['group'], 'unit' => $translated[$k]['unit']] + $ingredient;
	}

	return $ingredients;
}

/** A hash of the Russian texts a translation is made of. */
function foodies_translation_source_hash(int $source_id): string
{
	$post = get_post($source_id);

	return md5(serialize([$post->post_title, $post->post_content, get_post_meta($source_id, 'recipe_ingredients', true), get_post_meta($source_id, 'recipe_instructions', true)]));
}

/**
 * Imports translations of terms (database/content/translations/terms.{lang}.json in Laravel):
 * dish types, countries, ingredients. Creates the translation of a term or renames the one
 * imported before; an edited name is replaced only with --force. Safe to re-run.
 *
 *     wp foodies import-term-translations terms.en.json [--force]
 */
WP_CLI::add_command('foodies import-term-translations', function (array $args, array $assoc) {
	$data = json_decode((string) @file_get_contents($args[0] ?? ''), true);
	$lang = (string) ($data['lang'] ?? '');
	if (!function_exists('pll_insert_term') || !PLL()->model->languages->get($lang)) {
		WP_CLI::error('Not a file of term translations into a site language, or Polylang is not set up.');
	}
	$counts = ['created' => 0, 'renamed' => 0, 'kept' => 0, 'skipped' => 0];
	foreach (['course', 'cuisine', 'ingredient'] as $taxonomy) {
		foreach ($data[$taxonomy] ?? [] as $item) {
			$source = get_term((int) $item['term_id'], $taxonomy);
			if (!$source instanceof WP_Term) {
				WP_CLI::warning("$taxonomy #{$item['term_id']} ({$item['ru']}) not found.");
				$counts['skipped']++;
				continue;
			}
			if (pll_get_term_language($source->term_id) !== 'ru') {
				pll_set_term_language($source->term_id, 'ru');
			}
			$name = trim($item['name']);
			$id = pll_get_term($source->term_id, $lang);
			if ($id) {
				$term = get_term($id, $taxonomy);
				// Renamed in the admin since the last import: kept.
				if ($term->name === $name || (get_term_meta($id, 'foodies_imported_name', true) !== $term->name && empty($assoc['force']))) {
					$counts['kept']++;
					continue;
				}
				wp_update_term($id, $taxonomy, ['name' => $name]);
				update_term_meta($id, 'foodies_imported_name', $name);
				$counts['renamed']++;
				continue;
			}
			// Two Russian terms can have one English name ("Масло оливковое", "Оливковое масло"):
			// WordPress allows it with another slug.
			$base = sanitize_title($name) ?: "term-{$source->term_id}";
			$slug = $base;
			for ($i = 2; get_term_by('slug', $slug, $taxonomy); $i++) {
				$slug = "$base-$i";
			}
			$result = pll_insert_term($name, $taxonomy, $lang, ['slug' => $slug, 'translations' => ['ru' => $source->term_id]]);
			if (is_wp_error($result)) {
				WP_CLI::warning("$taxonomy «{$name}»: " . $result->get_error_message());
				$counts['skipped']++;
				continue;
			}
			update_term_meta($result['term_id'], 'foodies_imported_name', $name);
			$counts['created']++;
		}
	}
	foodies_seo_templates($lang, [0]);
	WP_CLI::success("{$counts['created']} created, {$counts['renamed']} renamed, {$counts['kept']} kept, {$counts['skipped']} skipped.");
});

/** Interface strings of the site (texts of buttons, headings, messages), with their default translations. */
function foodies_strings(): array
{
	$strings = json_decode((string) @file_get_contents(__DIR__ . '/foodies-strings.json'), true);

	return is_array($strings) ? $strings : [];
}

/**
 * Fills in the translations of interface strings nobody has translated in the admin yet
 * (Languages → Translations), from foodies-strings.json. Translations edited there are kept.
 *
 *     wp foodies import-strings
 */
WP_CLI::add_command('foodies import-strings', function () {
	if (!class_exists('PLL_MO')) {
		WP_CLI::error('Polylang is not active.');
	}
	$added = 0;
	foreach (PLL()->model->languages->get_list() as $language) {
		if ($language->slug === 'ru') {
			continue;
		}
		$mo = new PLL_MO();
		$mo->import_from_db($language);
		foreach (foodies_strings() as $string) {
			$default = (string) ($string[$language->slug] ?? '');
			if ($default !== '' && $mo->translate($string['ru']) === $string['ru']) {
				$mo->add_entry($mo->make_entry($string['ru'], $default));
				$added++;
			}
		}
		$mo->export_to_db($language);
	}
	WP_CLI::success("$added translations added.");
});

/**
 * English SEO fields from templates (Yoast's own templates are Russian): the Yoast title and
 * description of translated recipes and terms, and the SEO text of terms. Only empty fields are
 * filled, so ones written by hand are kept. Run after an import; safe to re-run.
 *
 *     wp foodies seo-templates [--lang=en]
 */
WP_CLI::add_command('foodies seo-templates', function (array $args, array $assoc) {
	$lang = (string) ($assoc['lang'] ?? 'en');
	if (!class_exists('WPSEO_Taxonomy_Meta') || !function_exists('pll_get_post_language')) {
		WP_CLI::error('Yoast SEO and Polylang must be active.');
	}
	$filled = foodies_seo_templates($lang);
	WP_CLI::success("Filled: {$filled['posts']} recipes, {$filled['terms']} terms, {$filled['texts']} SEO texts.");
});

/** Templates of foodies_seo_templates(): Yoast variables (%%title%%) are filled by Yoast, {name} here. */
const FOODIES_SEO_TEMPLATES = [
	'en' => [
		'recipe' => [
			// Short: the recipe's own title is long enough (Google shows ~60 characters).
			'title' => '%%title%% Recipe %%sep%% %%sitename%%',
			'description' => 'How to make %%title%% at home: ingredients, step-by-step instructions and photos. A Foodies Academy recipe.',
		],
		'course' => [
			'title' => '%%term_title%% Recipes with Photos %%sep%% %%sitename%%',
			'description' => '%%term_title%%: recipes with step-by-step photos on Foodies Academy, our readers\' favourites first.',
			'text' => '<p>{name} on Foodies Academy: tried-and-tested recipes with step-by-step photos, exact amounts and tips from our chefs. Pick a recipe, set the number of servings and cook with confidence.</p>',
		],
		'cuisine' => [
			'title' => '%%term_title%% Recipes %%sep%% %%sitename%%',
			'description' => 'Recipes of %%term_title%%: traditional and modern dishes with step-by-step photos on Foodies Academy.',
			'text' => '<p>Recipes of {name}: traditional and modern dishes collected by Foodies Academy chefs, with step-by-step photos and exact amounts. Discover new flavours without leaving your kitchen.</p>',
		],
		'ingredient' => [
			'title' => 'Recipes with %%term_title%% %%sep%% %%sitename%%',
			'description' => 'What to cook with %%term_title%%: recipes with step-by-step photos on Foodies Academy, our readers\' favourites first.',
			'text' => '<p>Recipes with {name}: our readers\' favourite dishes come first. Every recipe has step-by-step photos, exact amounts and a servings calculator.</p>',
		],
	],
];

/**
 * Fills the empty SEO fields of everything translated into $lang, or of $postIds / $termIds only
 * ([0]: none of that kind); returns the counts.
 */
function foodies_seo_templates(string $lang, array $postIds = [], array $termIds = []): array
{
	$templates = FOODIES_SEO_TEMPLATES[$lang] ?? null;
	$filled = ['posts' => 0, 'terms' => 0, 'texts' => 0];
	if (!$templates) {
		return $filled;
	}
	$posts = $postIds ?: get_posts(['post_type' => 'recipe', 'lang' => $lang, 'post_status' => ['publish', 'draft'], 'numberposts' => -1, 'fields' => 'ids']);
	foreach ($posts as $id) {
		if (pll_get_post_language($id) !== $lang) {
			continue;
		}
		$done = false;
		foreach (['title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc'] as $field => $key) {
			if (trim((string) get_post_meta($id, $key, true)) === '') {
				update_post_meta($id, $key, $templates['recipe'][$field]);
				$done = true;
			}
		}
		$filled['posts'] += (int) $done;
	}
	foreach (['course', 'cuisine', 'ingredient'] as $taxonomy) {
		$terms = $termIds ?: get_terms(['taxonomy' => $taxonomy, 'lang' => $lang, 'hide_empty' => false, 'fields' => 'ids']);
		foreach ($terms as $id) {
			$term = get_term($id, $taxonomy);
			if (!$term instanceof WP_Term || pll_get_term_language($id) !== $lang) {
				continue;
			}
			// One write of both fields: set_values() replaces the term's fields, and set_value() twice
			// keeps only the second one (it merges with a cached copy).
			$values = [];
			$done = false;
			foreach (['title' => 'wpseo_title', 'description' => 'wpseo_desc'] as $field => $key) {
				$value = trim((string) WPSEO_Taxonomy_Meta::get_term_meta($id, $taxonomy, str_replace('wpseo_', '', $key)));
				$done = $done || $value === '';
				$values[$key] = $value !== '' ? $value : $templates[$taxonomy][$field];
			}
			if ($done) {
				WPSEO_Taxonomy_Meta::set_values($id, $taxonomy, $values);
			}
			$filled['terms'] += (int) $done;
			if ($done) {
				foodies_seo_forget_term_index($id);
			}
			if (trim((string) get_term_meta($id, 'seo_text', true)) === '') {
				update_term_meta($id, 'seo_text', str_replace('{name}', esc_html($term->name), $templates[$taxonomy]['text']));
				$filled['texts']++;
			}
		}
	}

	return $filled;
}

/**
 * Yoast answers from its indexables table, which isn't rebuilt when the term meta is set from
 * code: the term's row is removed, and Yoast builds it again from the meta on the next request.
 */
function foodies_seo_forget_term_index(int $term_id): void
{
	global $wpdb;
	$table = $wpdb->prefix . 'yoast_indexable';
	$wpdb->query($wpdb->prepare("DELETE FROM `$table` WHERE object_type = 'term' AND object_id = %d", $term_id));
}

/**
 * Copies of the Contact Form 7 sign-up forms for another language: its subscribers are a list of
 * their own in CFDB7. The copy gets CF7's English messages; its ID goes to Laravel's .env
 * (CF7_SUBSCRIBE_FORM_ID_EN, CF7_BOOK_FORM_ID_EN). A form copied before is not copied again.
 *
 *     wp foodies copy-forms 28089 77 [--lang=en]
 */
WP_CLI::add_command('foodies copy-forms', function (array $args, array $assoc) {
	if (!class_exists('WPCF7_ContactForm')) {
		WP_CLI::error('Contact Form 7 is not active.');
	}
	$lang = (string) ($assoc['lang'] ?? 'en');
	foreach ($args as $source_id) {
		$source = WPCF7_ContactForm::get_instance((int) $source_id);
		if (!$source) {
			WP_CLI::warning("No form $source_id.");
			continue;
		}
		$existing = get_posts(['post_type' => 'wpcf7_contact_form', 'meta_key' => "foodies_copy_of_$lang", 'meta_value' => (string) $source_id, 'fields' => 'ids', 'numberposts' => 1]);
		if ($existing) {
			WP_CLI::log("{$source->title()} ($source_id): copy {$existing[0]} exists.");
			continue;
		}
		$copy = $source->copy();
		$copy->set_title($source->title() . ' (' . strtoupper($lang) . ')');
		$copy->set_locale($lang === 'en' ? 'en_US' : $lang);
		// CF7's own English messages: "Thank you for your message. It has been sent." …
		$copy->set_properties(['messages' => array_map(fn ($message) => $message['default'], wpcf7_messages())]);
		$id = $copy->save();
		update_post_meta($id, "foodies_copy_of_$lang", (string) $source_id);
		WP_CLI::log("{$source->title()} ($source_id) → $id");
	}
});
