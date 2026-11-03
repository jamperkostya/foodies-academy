<?php

namespace App\Support;

/** HTML of WordPress editors (block or classic) ready for the site; "" when there is no text. */
class WpHtml
{
    public static function render(?string $html): string
    {
        // Block editor markers: <!-- wp:paragraph --> … <!-- /wp:paragraph -->
        $html = trim(preg_replace('/<!--\s*\/?wp:[\s\S]*?-->/', '', (string) $html));
        if ($html === '' || trim(strip_tags($html, '<img><iframe>')) === '') {
            return '';
        }
        // The classic editor saves bare lines; like wpautop, text between blank lines becomes a
        // paragraph unless it already starts with a block tag (<h2>, <ul>…).
        // A block tag ends a paragraph too: "<h2>…</h2>\nText" is a heading and a paragraph.
        $html = preg_replace('~(</(?:p|div|h[1-6]|ul|ol|table|blockquote|figure)>)~i', "$1\n\n", $html);
        $html = preg_replace('~(<(?:div|h[1-6]|ul|ol|table|blockquote|figure)\b)~i', "\n\n$1", $html);
        $blocks = preg_split('/\R\s*\R/', $html);
        $html = implode("\n", array_map(function ($block) {
            $block = trim($block);

            return preg_match('/^<(p|div|h[1-6]|ul|ol|table|blockquote|figure|iframe|hr)\b/i', $block)
                ? $block
                : '<p>'.nl2br($block, false).'</p>';
        }, array_filter($blocks, fn ($block) => trim($block) !== '')));

        return $html;
    }
}
