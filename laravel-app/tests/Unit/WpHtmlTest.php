<?php

namespace Tests\Unit;

use App\Services\PostViews;
use App\Services\Recipes;
use App\Support\WpHtml;
use PHPUnit\Framework\TestCase;

class WpHtmlTest extends TestCase
{
    public function test_classic_editor_text_becomes_paragraphs(): void
    {
        $this->assertSame("<p>Раз<br>\nдва</p>\n<p>Три</p>", WpHtml::render("Раз\nдва\n\nТри"));
        $this->assertSame("<h2>Заголовок</h2>\n<p>Текст</p>", WpHtml::render("<h2>Заголовок</h2>\nТекст"));
        $this->assertSame("<p>Список:</p>\n<ul>\n<li>а</li>\n<li>б</li>\n</ul>", WpHtml::render("Список:\n<ul>\n<li>а</li>\n<li>б</li>\n</ul>"));
    }

    public function test_block_editor_markers_are_removed_and_empty_text_is_empty(): void
    {
        $this->assertSame('<p>Текст</p>', WpHtml::render("<!-- wp:paragraph -->\n<p>Текст</p>\n<!-- /wp:paragraph -->"));
        $this->assertSame('', WpHtml::render("<p> </p>\n<!-- wp:spacer /-->"));
        $this->assertSame('', WpHtml::render(null));
    }

    public function test_net_likes(): void
    {
        $this->assertSame(7, Recipes::netLikes(['pld_like_count' => '10', 'pld_dislike_count' => '3']));
        // A dislike written with a minus is still subtracted once.
        $this->assertSame(7, Recipes::netLikes(['pld_like_count' => '10', 'pld_dislike_count' => '-3']));
        $this->assertSame(-2, Recipes::netLikes(['pld_dislike_count' => '2']));
    }

    public function test_bots(): void
    {
        $this->assertTrue(PostViews::isBot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
        $this->assertTrue(PostViews::isBot('facebookexternalhit/1.1'));
        $this->assertTrue(PostViews::isBot(''));
        $this->assertFalse(PostViews::isBot('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1'));
    }
}
