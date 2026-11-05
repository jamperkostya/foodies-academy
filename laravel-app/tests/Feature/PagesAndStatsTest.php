<?php

namespace Tests\Feature;

use Tests\SiteTestCase;

class PagesAndStatsTest extends SiteTestCase
{
    public function test_site_pages_from_wordpress(): void
    {
        $this->makePost('page', 'contacts', ['post_title' => 'Контакты', 'post_excerpt' => 'Как связаться', 'post_content' => "<h2>Адрес</h2>\nКиев\n\nТелефон"], ['foodies_site_page' => '1']);
        $this->makePost('page', 'cart', ['post_title' => 'Корзина']);

        $this->getJson('/api/pages')->assertJsonPath('data', [['slug' => 'contacts', 'title' => 'Контакты']]);
        $this->getJson('/api/pages/contacts')
            ->assertJsonPath('data.excerpt', 'Как связаться')
            ->assertJsonPath('data.content', "<h2>Адрес</h2>\n<p>Киев</p>\n<p>Телефон</p>");
        $this->getJson('/api/pages/nope')->assertNotFound();
    }

    public function test_home_is_the_wordpress_front_page(): void
    {
        $id = $this->makePost('page', 'glavnaya', [], ['seo_text' => '<p>О сайте</p>']);
        $this->site()->table('options')->insert(['option_name' => 'page_on_front', 'option_value' => (string) $id, 'autoload' => 'yes']);

        $this->getJson('/api/pages/home')->assertJsonPath('data.seoText', '<p>О сайте</p>');
    }

    public function test_category_page_with_seo_text(): void
    {
        $id = $this->recipe('borscht');
        $soups = $this->term('course', 'soups', 'Супы', [$id]);
        $this->site()->table('termmeta')->insert(['term_id' => $soups, 'meta_key' => 'seo_text', 'meta_value' => '<p>Всё о супах</p>']);

        $this->getJson('/api/categories/soups')
            ->assertJsonPath('data.name', 'Супы')
            ->assertJsonPath('data.seoText', '<p>Всё о супах</p>')
            ->assertJsonPath('data.recipes.0.slug', 'borscht');
    }

    public function test_category_images_from_both_theme_fields(): void
    {
        $image = function (string $file) {
            $id = $this->makePost('attachment', $file);
            $this->meta($id, '_wp_attachment_metadata', serialize(['file' => "2018/09/$file.jpg"]));

            return $id;
        };
        $id = $this->recipe('borscht');
        $soups = $this->term('course', 'soups', 'Супы', [$id]);
        $salads = $this->term('course', 'salads', 'Салаты', [$id]);
        // Carbon Fields (the site's database) and the old theme's ACF field (the live one).
        $this->site()->table('termmeta')->insert([
            ['term_id' => $soups, 'meta_key' => '_crb_category_thumb', 'meta_value' => (string) $image('soups')],
            ['term_id' => $salads, 'meta_key' => 'image', 'meta_value' => (string) $image('salads')],
        ]);

        $uploads = rtrim(config('services.academy.uploads_url'), '/');
        $images = collect($this->getJson('/api/categories?type=course')->json('data'))->pluck('image', 'slug');
        $this->assertSame(["$uploads/2018/09/salads.jpg", "$uploads/2018/09/soups.jpg"], [$images['salads'], $images['soups']]);
    }

    public function test_trust_block_totals(): void
    {
        $a = $this->recipe('a', [], ['pld_like_count' => '10', 'pld_dislike_count' => '2']);
        $this->recipe('b');
        $this->makePost('recipe', 'draft', ['post_status' => 'draft']);
        $this->comment($a, 'Анна', [], 5);
        $this->comment($a, 'Спам', ['comment_approved' => '0'], 1);
        $this->site()->table('post_views_realtime')->insert([
            ['post_id' => $a, 'view_type' => 'normal', 'output_type' => 'content', 'post_views_today' => 0, 'post_views_week' => 0, 'post_views_month' => 0, 'post_views_halfyear' => 0, 'post_views_year' => 0, 'post_views_total' => 500, 'latest_view_time' => '2026-01-01'],
            ['post_id' => $a, 'view_type' => 'robot', 'output_type' => 'content', 'post_views_today' => 0, 'post_views_week' => 0, 'post_views_month' => 0, 'post_views_halfyear' => 0, 'post_views_year' => 0, 'post_views_total' => 900, 'latest_view_time' => '2026-01-01'],
        ]);

        $this->getJson('/api/stats')->assertJsonPath('data', ['recipes' => 2, 'ratings' => 13, 'views' => 500, 'comments' => 1]);
    }

    public function test_legacy_likes_are_imported_once(): void
    {
        $id = $this->recipe('a', [], ['like_post' => '100', 'unlike_post' => '7', 'pld_like_count' => '2']);

        $this->artisan('likes:import-legacy')->assertSuccessful();
        $this->artisan('likes:import-legacy')->expectsOutputToContain('Imported likes of 0 posts')->assertSuccessful();

        $this->assertSame('102', $this->postMeta($id, 'pld_like_count'));
        $this->assertSame('7', $this->postMeta($id, 'pld_dislike_count'));
        $this->assertSame('100', $this->postMeta($id, 'like_post'));
    }
}
