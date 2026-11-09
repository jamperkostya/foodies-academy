<?php

namespace Tests\Feature;

use Tests\SiteTestCase;

class NewsAndBlogTest extends SiteTestCase
{
    public function test_news_and_blog_are_separate_post_types(): void
    {
        $author = $this->user('oleg', 'Олег');
        $news = $this->makePost('news', 'festival', ['post_title' => 'Фестиваль', 'post_author' => $author, 'post_date' => '2026-10-01 10:00:00']);
        $this->term('news_category', 'events', 'События', [$news]);
        $post = $this->makePost('post', 'mango', ['post_title' => 'Как сушить манго', 'post_author' => $author, 'post_content' => "Первый абзац\n\nВторой"]);
        $this->term('category', 'novosti', 'Новости', [$post]);

        $this->getJson('/api/news')->assertJsonPath('data.*.slug', ['festival'])->assertJsonPath('data.0.category', 'События');
        $this->getJson('/api/blog')->assertJsonPath('data.*.slug', ['mango'])->assertJsonPath('data.0.category', 'Новости');
        $this->getJson('/api/news/mango')->assertNotFound();
        $this->getJson('/api/blog/festival')->assertNotFound();

        $this->getJson('/api/blog/mango')
            ->assertJsonPath('data.content', "<p>Первый абзац</p>\n<p>Второй</p>")
            ->assertJsonPath('data.author', ['slug' => 'oleg', 'name' => 'Олег'])
            ->assertJsonPath('data.excerpt', 'Первый абзац Второй');
        $this->getJson('/api/news/festival')->assertJsonPath('data.dateText', '1 октября 2026');
        $this->getJson('/api/blog/slugs')->assertJsonPath('data', ['mango']);
    }

    public function test_post_cover_falls_back_to_a_text_image_then_the_video(): void
    {
        $photo = $this->makePost('attachment', 'grill');
        $this->meta($photo, '_wp_attachment_metadata', serialize(['file' => '2020/12/grill.jpg']));
        $this->makePost('post', 'featured', ['post_date' => '2026-01-04 10:00:00'], ['_thumbnail_id' => (string) $photo]);
        $this->makePost('post', 'in-text', [
            'post_date' => '2026-01-03 10:00:00',
            // An outside image first: hotlinked pictures of other sites are not used as covers.
            'post_content' => '<img src="https://other.site/a.jpg" alt=""/><img src="x" class="wp-image-'.$photo.'"/>',
        ]);
        $this->makePost('post', 'with-video', ['post_date' => '2026-01-02 10:00:00'], ['link_to_video' => 'https://www.youtube.com/watch?v=GV3LIDEYkiU']);
        $this->makePost('post', 'nothing', ['post_date' => '2026-01-01 10:00:00']);

        $cover = rtrim(config('services.academy.uploads_url'), '/').'/2020/12/grill.jpg';
        $this->getJson('/api/blog')->assertJsonPath('data.*.image', [$cover, $cover, 'https://i.ytimg.com/vi/GV3LIDEYkiU/hqdefault.jpg', '']);
    }

    public function test_comments_on_news_and_blog_wait_for_moderation(): void
    {
        $post = $this->makePost('post', 'mango');
        $news = $this->makePost('news', 'festival');

        $this->postJson('/api/blog/mango/comments', ['name' => 'Анна', 'text' => 'Полезно!'])->assertCreated();
        $this->postJson('/api/news/festival/comments', ['name' => 'Анна', 'text' => 'Приду!'])->assertCreated();
        $this->postJson('/api/news/mango/comments', ['name' => 'Анна', 'text' => 'Приду!'])->assertNotFound();

        $this->assertSame(['0', '0'], $this->site()->table('comments')->whereIn('comment_post_ID', [$post, $news])->pluck('comment_approved')->all());
    }

    public function test_author_page_lists_news_and_blog_posts(): void
    {
        $author = $this->user('oleg', 'Олег');
        $this->recipe('borscht', ['post_author' => $author]);
        $this->makePost('news', 'festival', ['post_author' => $author]);
        $this->makePost('post', 'mango', ['post_author' => $author]);

        $this->getJson('/api/authors/oleg')
            ->assertJsonPath('data.news.*.slug', ['festival'])
            ->assertJsonPath('data.posts.*.slug', ['mango']);
    }

    public function test_author_bio_is_html_with_paragraphs(): void
    {
        $author = $this->user('oleg', 'Олег');
        $this->recipe('borscht', ['post_author' => $author]);
        // Older bios are plain lines; the visual editor saves HTML, which passes as is.
        $this->site()->table('usermeta')->insert(['user_id' => $author, 'meta_key' => 'description', 'meta_value' => "Повар.\n\nПишу о <strong>кухне</strong>."]);

        $this->getJson('/api/authors/oleg')
            ->assertJsonPath('data.bio', "<p>Повар.</p>\n<p>Пишу о <strong>кухне</strong>.</p>");
    }

    public function test_content_without_wpml_rows_is_on_the_site(): void
    {
        // Made in the docker WordPress, which has no WPML: no icl_translations rows at all.
        $this->recipe('new-recipe');

        $this->getJson('/api/recipes/slugs')->assertJsonPath('data', ['new-recipe']);
        $this->getJson('/api/stats')->assertJsonPath('data.recipes', 1);
    }
}
