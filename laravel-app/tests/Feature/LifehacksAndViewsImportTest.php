<?php

namespace Tests\Feature;

use Tests\SiteTestCase;

class LifehacksAndViewsImportTest extends SiteTestCase
{
    public function test_lifehacks_from_wordpress(): void
    {
        $author = $this->user('oleg', 'Олег');
        $this->recipe('borscht', ['post_author' => $author]);
        $id = $this->makePost('lifehack', 'pasta-mistakes', [
            'post_title' => '10 ошибок с пастой', 'post_excerpt' => 'Разбираем мифы', 'post_author' => $author, 'post_date' => '2026-08-03 10:00:00',
        ], [
            'lifehack_icon' => '🍝',
            'lifehack_title_accent' => '— и как их избежать',
            'lifehack_intro' => 'Вступление',
            'lifehack_points' => "Мало воды. | Литр на 100 г.\nПросто совет без заголовка\n\n",
            'lifehack_tip_title' => 'Главное правило',
            'lifehack_tip_text' => 'Солите воду',
            'lifehack_video_minutes' => '3',
            'lifehack_image_url' => 'https://img.test/pasta.jpg',
        ]);
        $this->term('lifehack_category', 'servirovka', 'Сервировка', [$id]);
        $this->makePost('lifehack', 'draft', ['post_status' => 'draft']);
        $this->comment($id, 'Мария');

        $this->getJson('/api/lifehacks')->assertJsonCount(1, 'data')->assertJsonPath('data.0.commentCount', 0);
        $this->getJson('/api/lifehacks/pasta-mistakes')
            ->assertOk()
            ->assertJsonPath('data.title', '10 ошибок с пастой')
            ->assertJsonPath('data.titleAccent', '— и как их избежать')
            ->assertJsonPath('data.category', 'Сервировка')
            ->assertJsonPath('data.icon', '🍝')
            ->assertJsonPath('data.image', 'https://img.test/pasta.jpg')
            ->assertJsonPath('data.description', 'Разбираем мифы')
            ->assertJsonPath('data.points', [['title' => 'Мало воды.', 'text' => 'Литр на 100 г.'], ['title' => '', 'text' => 'Просто совет без заголовка']])
            ->assertJsonPath('data.tip', ['title' => 'Главное правило', 'text' => 'Солите воду'])
            ->assertJsonPath('data.author', ['slug' => 'oleg', 'name' => 'Олег', 'info' => '1 рецепт'])
            ->assertJsonPath('data.date', '3 августа 2026')
            ->assertJsonPath('data.videoMinutes', 3)
            ->assertJsonPath('data.comments.0.name', 'Мария');
        $this->getJson('/api/lifehacks/draft')->assertNotFound();
    }

    public function test_lifehack_comments_wait_for_moderation(): void
    {
        $id = $this->makePost('lifehack', 'onion');

        $this->postJson('/api/lifehacks/onion/comments', ['name' => 'Анна', 'text' => 'Работает!'])->assertCreated();
        $this->assertSame('0', $this->site()->table('comments')->where('comment_post_ID', $id)->value('comment_approved'));
        $this->postJson('/api/lifehacks/nope/comments', ['name' => 'Анна', 'text' => 'Работает!'])->assertNotFound();
    }

    public function test_old_views_are_copied_into_post_views_counter_once(): void
    {
        $id = $this->recipe('borscht');
        $this->site()->table('post_views_realtime')->insert([
            ['post_id' => $id, 'view_type' => 'normal', 'output_type' => 'content', 'post_views_today' => 0, 'post_views_week' => 0, 'post_views_month' => 0, 'post_views_halfyear' => 0, 'post_views_year' => 0, 'post_views_total' => 1631, 'latest_view_time' => '2023-01-01'],
            ['post_id' => $id, 'view_type' => 'robot', 'output_type' => 'content', 'post_views_today' => 0, 'post_views_week' => 0, 'post_views_month' => 0, 'post_views_halfyear' => 0, 'post_views_year' => 0, 'post_views_total' => 999, 'latest_view_time' => '2023-01-01'],
        ]);
        $this->site()->table('post_views_history')->insert([
            'year_id' => 2020, 'post_id' => $id, 'view_type' => 'normal', 'output_type' => 'content',
            'post_views_today' => '-1', 'post_views_week' => '-1', 'post_views_month' => '-1,20,0,134,0,0,0,0,0,0,0,0,191', 'post_views_halfyear' => '-1', 'post_views_year' => '-1,345',
        ]);

        $this->artisan('views:import-legacy')->assertSuccessful();
        $this->artisan('views:import-legacy')->assertSuccessful();

        // assertEquals: the order of the rows doesn't matter.
        $this->assertEquals(
            ['202001' => 20, '202003' => 134, '202012' => 191, '2020' => 345, 'total' => 1631],
            $this->site()->table('post_views')->where('id', $id)->orderBy('period')->pluck('count', 'period')->map(fn ($n) => (int) $n)->all(),
        );
    }

    public function test_ingredient_page_with_seo_text(): void
    {
        $id = $this->recipe('borscht');
        $salt = $this->term('ingredient', 'sol', 'Соль', [$id]);
        $this->site()->table('termmeta')->insert(['term_id' => $salt, 'meta_key' => 'seo_text', 'meta_value' => '<p>Всё о соли</p>']);

        $this->getJson('/api/ingredients/sol')
            ->assertJsonPath('data.name', 'Соль')
            ->assertJsonPath('data.seoText', '<p>Всё о соли</p>')
            ->assertJsonPath('data.recipes.0.slug', 'borscht');
    }

    public function test_every_ingredient_with_a_published_recipe(): void
    {
        $borscht = $this->recipe('borscht');
        $draft = $this->recipe('draft', ['post_status' => 'draft']);
        $this->term('ingredient', 'sol', 'Соль', [$borscht, $draft]);
        $this->term('ingredient', 'svekla', 'Свёкла', [$borscht]);
        // Only in a draft: no page.
        $this->term('ingredient', 'tmin', 'Тмин', [$draft]);

        $this->getJson('/api/ingredients')->assertExactJson(['data' => [
            ['name' => 'Свёкла', 'slug' => 'svekla', 'count' => 1],
            ['name' => 'Соль', 'slug' => 'sol', 'count' => 1],
        ]]);
    }
}
