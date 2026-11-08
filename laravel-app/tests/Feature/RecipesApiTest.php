<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\SiteTestCase;

class RecipesApiTest extends SiteTestCase
{
    public function test_recipe_page_data(): void
    {
        $id = $this->recipe('borscht', ['post_title' => 'Борщ'], [
            'recipe_servings' => '4',
            'recipe_ingredients' => serialize([['ingredient' => 'Свёкла', 'amount' => '2', 'amount_normalized' => '2', 'unit' => 'шт', 'notes' => '', 'group' => '']]),
            'pld_like_count' => '10',
            'pld_dislike_count' => '3',
            'seo_text' => "Первый абзац\n\nВторой",
        ]);
        $this->term('course', 'soups', 'Супы', [$id]);
        $this->term('cuisine', 'ukraina', 'Украина', [$id]);

        $this->getJson('/api/recipes/borscht')
            ->assertOk()
            ->assertJsonPath('data.title', 'Борщ')
            ->assertJsonPath('data.servings', 4)
            ->assertJsonPath('data.ingredients.0.name', 'Свёкла')
            ->assertJsonPath('data.votes', ['like' => 10, 'dislike' => 3])
            // Cards show likes minus dislikes.
            ->assertJsonPath('data.likes.count', 7)
            ->assertJsonPath('data.seoText', '<p>Первый абзац</p>'."\n".'<p>Второй</p>')
            ->assertJsonPath('data.categoryLinks.course.0', ['name' => 'Супы', 'slug' => 'soups'])
            ->assertJsonPath('data.categoryLinks.country.0', ['name' => 'Украина', 'slug' => 'ukraina']);
    }

    public function test_last_change_and_focus_keyword_for_the_structured_data(): void
    {
        $this->recipe('borscht', ['post_modified_gmt' => '2026-03-01 10:00:00'], ['_yoast_wpseo_focuskw' => ' борщ с пампушками ']);
        $this->recipe('plain', ['post_date_gmt' => '2025-05-02 08:00:00', 'post_modified_gmt' => '0000-00-00 00:00:00']);

        $this->getJson('/api/recipes/borscht')
            ->assertJsonPath('data.updatedAt', '2026-03-01T10:00:00+00:00')
            ->assertJsonPath('data.focusKeyword', 'борщ с пампушками');
        // Never changed: the publication date; no keyword: "".
        $this->getJson('/api/recipes/plain')
            ->assertJsonPath('data.updatedAt', '2025-05-02T08:00:00+00:00')
            ->assertJsonPath('data.focusKeyword', '');
    }

    public function test_sitemap_lists_every_recipe_with_its_photos_in_full_size(): void
    {
        $photo = fn (string $file) => tap($this->makePost('attachment', $file), fn ($id) => $this->meta($id, '_wp_attachment_metadata', serialize([
            'file' => "2018/11/$file.jpg", 'sizes' => ['medium' => ['file' => "$file-601x601.jpg"]],
        ])));
        $cover = $photo('cover');
        $second = $photo('second');
        $this->recipe('borscht', ['post_modified_gmt' => '2026-03-01 10:00:00'], ['gallery' => serialize([(string) $cover, (string) $second])]);
        $this->recipe('draft', ['post_status' => 'draft']);
        $uploads = config('services.academy.uploads_url');

        $this->getJson('/api/recipes/sitemap')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'borscht')
            ->assertJsonPath('data.0.modified', '2026-03-01T10:00:00+00:00')
            ->assertJsonPath('data.0.images', ["$uploads/2018/11/cover.jpg", "$uploads/2018/11/second.jpg"]);
    }

    public function test_gallery_video_and_description(): void
    {
        $photo = fn (string $file) => tap($this->makePost('attachment', $file), fn ($id) => $this->meta($id, '_wp_attachment_metadata', serialize(['file' => "2018/11/$file.jpg"])));
        [$cover, $second] = [$photo('cover'), $photo('second')];
        $this->recipe('carbonara', [], [
            '_thumbnail_id' => (string) $cover,
            'gallery' => serialize([(string) $cover, (string) $second]),
            'recipe_video_embed' => 'https://www.youtube.com/watch?v=H89sF9Kdw_4&t=13s',
            'recipe_description' => ' Паста без сливок. ',
        ]);
        $this->recipe('plain', [], ['recipe_video_embed' => '123123123']);

        $uploads = rtrim(config('services.academy.uploads_url'), '/');
        $this->getJson('/api/recipes/carbonara')
            ->assertJsonPath('data.gallery', ["$uploads/2018/11/cover.jpg", "$uploads/2018/11/second.jpg"])
            ->assertJsonPath('data.youtubeId', 'H89sF9Kdw_4')
            ->assertJsonPath('data.description', 'Паста без сливок.');
        // Not a YouTube link: no video; no photos: an empty gallery.
        $this->getJson('/api/recipes/plain')
            ->assertJsonPath('data.youtubeId', null)
            ->assertJsonPath('data.gallery', [])
            ->assertJsonPath('data.description', '');
    }

    public function test_video_history_and_cover_from_the_old_theme_fields(): void
    {
        // The live database keeps them in the ACF fields "video" and "history", and has no featured
        // images: the cover is the first photo of the gallery.
        $photo = $this->makePost('attachment', 'pasta');
        $this->meta($photo, '_wp_attachment_metadata', serialize(['file' => '2018/11/pasta.jpg']));
        $this->recipe('carbonara', [], [
            'video' => 'https://youtu.be/H89sF9Kdw_4',
            'history' => '<p>Рим, 1944 год.</p>',
            'gallery' => serialize([(string) $photo]),
        ]);
        $cover = rtrim(config('services.academy.uploads_url'), '/').'/2018/11/pasta.jpg';

        $this->getJson('/api/recipes/carbonara')
            ->assertJsonPath('data.youtubeId', 'H89sF9Kdw_4')
            ->assertJsonPath('data.history', '<p>Рим, 1944 год.</p>')
            ->assertJsonPath('data.image', $cover)
            ->assertJsonPath('data.gallery', [$cover]);
        $this->getJson('/api/recipes/new')->assertJsonPath('data.0.image', $cover);
    }

    public function test_plain_text_tip_and_history_become_paragraphs(): void
    {
        // WP Ultimate Recipe's editor saves the notes as plain lines, without <p>.
        $this->recipe('olivier', [], [
            'recipe_notes' => "*Вместо рябчиков — перепела.\r\n\r\n** Икру можно заменить.",
            'history' => "Придуман в 1860-х.\n\nРецепт держали в секрете.",
        ]);

        $this->getJson('/api/recipes/olivier')
            ->assertJsonPath('data.tip', "<p>*Вместо рябчиков — перепела.</p>\n<p>** Икру можно заменить.</p>")
            ->assertJsonPath('data.history', "<p>Придуман в 1860-х.</p>\n<p>Рецепт держали в секрете.</p>");
    }

    public function test_slug_with_underscore_and_unknown_recipe(): void
    {
        $this->recipe('fua_gra-terrine');

        $this->getJson('/api/recipes/slugs')->assertJsonPath('data', ['fua_gra-terrine']);
        $this->getJson('/api/recipes/fua_gra-terrine')->assertOk();
        $this->getJson('/api/recipes/no-such-recipe')->assertNotFound();
    }

    public function test_rating_is_the_average_of_approved_reviews(): void
    {
        $id = $this->recipe('pasta', [], ['recipe_rating' => '3']);
        $this->comment($id, 'Анна', [], 5);
        $this->comment($id, 'Олег', [], 4);
        $this->comment($id, 'Спам', ['comment_approved' => '0'], 1);

        $this->getJson('/api/recipes/pasta')
            ->assertJsonPath('data.rating', 4.5)
            ->assertJsonPath('data.ratingCount', 2);
    }

    public function test_unrated_recipe_keeps_the_wp_ultimate_recipe_rating(): void
    {
        $this->recipe('salad', [], ['recipe_rating' => '3']);

        $this->getJson('/api/recipes/salad')->assertJsonPath('data.rating', 3)->assertJsonPath('data.ratingCount', 0);
    }

    public function test_yoast_seo_fields(): void
    {
        $this->recipe('borscht');
        Http::fake(['wordpress.test/wp-json/wp/v2/recipe*' => Http::response([[
            'yoast_head_json' => [
                'title' => 'Борщ — рецепт',
                'description' => 'Как сварить борщ',
                'robots' => ['index' => 'noindex', 'follow' => 'follow'],
                'og_title' => 'Борщ',
                'og_image' => [['url' => 'https://img.test/borscht.jpg', 'width' => 1200, 'height' => 630, 'type' => 'image/jpeg']],
                'twitter_card' => 'summary_large_image',
            ],
        ]])]);

        $this->getJson('/api/recipes/borscht')
            ->assertJsonPath('data.seo.title', 'Борщ — рецепт')
            ->assertJsonPath('data.seo.description', 'Как сварить борщ')
            ->assertJsonPath('data.seo.index', false)
            ->assertJsonPath('data.seo.og.image.url', 'https://img.test/borscht.jpg');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'slug=borscht'));
    }
}
