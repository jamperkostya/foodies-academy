<?php
/**
 * Plugin Name: Foodies — История происхождения
 * Description: Шаблон блоков «История происхождения» для рецептов. Astro стилизует его по классам origin-story__*.
 */

defined('ABSPATH') || exit;

add_action('init', function () {
	register_block_pattern_category('foodies', ['label' => 'Foodies']);

	register_block_pattern('foodies/origin-story', [
		'title'       => 'История происхождения',
		'description' => 'Вступление, цифры, версии происхождения, лента дат, «А вы знали?» и цитата. Лишние части можно удалить, карточки — дублировать.',
		'categories'  => ['foodies'],
		'keywords'    => ['история', 'происхождение', 'origin'],
		'content'     => foodies_origin_story_pattern(),
	]);
});

/**
 * Markup contract with Astro (classes go to "Дополнительные CSS-классы"):
 * .origin-story                    — wrapper
 *   p.origin-story__lead           — intro
 *   .origin-story__facts > .origin-story__fact > p.origin-story__fact-value + p.origin-story__fact-label
 *   h3                             — subsection heading
 *   .origin-story__theories > .origin-story__theory > p.origin-story__tag + h4 + p
 *   .origin-story__timeline > .origin-story__item > p.origin-story__year + h4 + p
 *   .origin-story__note > h4 + p   — «А вы знали?»
 *   blockquote.origin-story__quote — closing quote, optional cite
 */
function foodies_origin_story_pattern(): string {
	$p = fn(string $text, string $class = '') => $class
		? "<!-- wp:paragraph {\"className\":\"$class\"} -->\n<p class=\"$class\">$text</p>\n<!-- /wp:paragraph -->\n"
		: "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->\n";
	$h = fn(int $level, string $text) =>
		"<!-- wp:heading {\"level\":$level} -->\n<h$level class=\"wp-block-heading\">$text</h$level>\n<!-- /wp:heading -->\n";
	$group = fn(string $class, string $inner) =>
		"<!-- wp:group {\"className\":\"$class\"} -->\n<div class=\"wp-block-group $class\">$inner</div>\n<!-- /wp:group -->\n";

	$fact = fn(string $value, string $label) =>
		$group('origin-story__fact', $p($value, 'origin-story__fact-value') . $p($label, 'origin-story__fact-label'));
	$theory = fn(string $tag, string $title, string $text) =>
		$group('origin-story__theory', $p($tag, 'origin-story__tag') . $h(4, $title) . $p($text));
	$item = fn(string $year, string $title, string $text) =>
		$group('origin-story__item', $p($year, 'origin-story__year') . $h(4, $title) . $p($text));

	return $group('origin-story',
		$p('Расскажите, откуда взялось блюдо и почему оно так называется. Первая буква станет буквицей.', 'origin-story__lead')
		. $group('origin-story__facts',
			$fact('1950', 'первое упоминание')
			. $fact('5', 'ингредиентов')
			. $fact('0 мл', 'сливок'))
		. $h(3, 'Откуда название')
		. $group('origin-story__theories',
			$theory('Легенда', 'Название версии', 'Коротко о версии.')
			. $theory('Версия', 'Название версии', 'Коротко о версии.'))
		. $h(3, 'Как менялся рецепт')
		. $group('origin-story__timeline',
			$item('1944', 'Событие', 'Что произошло.')
			. $item('Сегодня', 'Событие', 'Что произошло.'))
		. $group('origin-story__note', $h(4, 'А вы знали?') . $p('Неожиданный факт о блюде.'))
		. "<!-- wp:quote {\"className\":\"origin-story__quote\"} -->\n<blockquote class=\"wp-block-quote origin-story__quote\">"
		. $p('Вывод одной фразой.')
		. "<cite>Редакция Foodies</cite></blockquote>\n<!-- /wp:quote -->\n"
	);
}

// Light outlines in the editor so the editor sees which part is which.
add_action('enqueue_block_assets', function () {
	if (!is_admin()) {
		return;
	}
	wp_register_style('foodies-origin-story-editor', false);
	wp_enqueue_style('foodies-origin-story-editor');
	wp_add_inline_style('foodies-origin-story-editor', '
		.origin-story [class*="origin-story__"]:is(.wp-block-group) { position: relative; outline: 1px dashed #EB5B60; outline-offset: 4px; margin-block: 12px; }
		.origin-story .wp-block-group[class*="origin-story__"]::before { position: absolute; top: -18px; left: 0; font: 600 10px/1 sans-serif; text-transform: uppercase; color: #EB5B60; }
		.origin-story__facts::before { content: "Цифры"; }
		.origin-story__fact::before { content: "Факт"; }
		.origin-story__theories::before { content: "Версии"; }
		.origin-story__theory::before { content: "Версия"; }
		.origin-story__timeline::before { content: "Лента дат"; }
		.origin-story__item::before { content: "Дата"; }
		.origin-story__note::before { content: "А вы знали?"; }
		.origin-story__year, .origin-story__fact-value { color: #EB5B60; font-weight: 800; }
		.origin-story__tag { display: inline-block; padding: 2px 10px; border-radius: 99px; background: #EB5B60; color: #fff; font-size: 12px; }
	');
});
