<?php

namespace Tests\Feature;

use Tests\SiteTestCase;

class MoveRecipeLegacyFieldsTest extends SiteTestCase
{
    public function test_moves_the_old_video_link_to_the_field_the_recipe_editor_shows(): void
    {
        $old = $this->recipe('carbonara', [], ['video' => 'https://www.youtube.com/watch?v=0ApJmk4Wx2Q', '_video' => 'field_5ae25a2d52f7c', 'recipe_video_embed' => '']);
        $kept = $this->recipe('plov', [], ['video' => 'https://www.youtube.com/watch?v=H89sF9Kdw_4', 'recipe_video_embed' => 'https://youtu.be/Uqem6B2teIw']);
        $none = $this->recipe('borscht', [], ['video' => '']);
        $article = $this->makePost('post', 'news', [], ['video' => 'https://www.youtube.com/watch?v=9PpHeqQ2src']);

        $this->artisan('recipes:move-legacy-fields')->assertSuccessful();
        $this->artisan('recipes:move-legacy-fields')->assertSuccessful();

        $this->assertSame('https://www.youtube.com/watch?v=0ApJmk4Wx2Q', $this->postMeta($old, 'recipe_video_embed'));
        $this->assertSame(1, $this->site()->table('postmeta')->where('post_id', $old)->where('meta_key', 'recipe_video_embed')->count());
        // The editor's own value wins.
        $this->assertSame('https://youtu.be/Uqem6B2teIw', $this->postMeta($kept, 'recipe_video_embed'));
        $this->assertFalse($this->site()->table('postmeta')->whereIn('post_id', [$old, $kept, $none])->whereIn('meta_key', ['video', '_video'])->exists());
        // Only recipes.
        $this->assertSame('https://www.youtube.com/watch?v=9PpHeqQ2src', $this->postMeta($article, 'video'));
    }

    public function test_a_video_cleared_in_the_admin_stays_cleared(): void
    {
        $id = $this->recipe('carbonara', [], ['video' => 'https://www.youtube.com/watch?v=0ApJmk4Wx2Q']);
        $this->artisan('recipes:move-legacy-fields')->assertSuccessful();
        $this->getJson('/api/recipes/carbonara')->assertJsonPath('data.youtubeId', '0ApJmk4Wx2Q');

        // Edited in WP Ultimate Recipe's "Video" field: a new link, then emptied.
        $this->site()->table('postmeta')->where('post_id', $id)->where('meta_key', 'recipe_video_embed')->update(['meta_value' => '<iframe src="https://www.youtube.com/embed/H89sF9Kdw_4"></iframe>']);
        $this->artisan('cache:clear');
        $this->getJson('/api/recipes/carbonara')->assertJsonPath('data.youtubeId', 'H89sF9Kdw_4');

        $this->site()->table('postmeta')->where('post_id', $id)->where('meta_key', 'recipe_video_embed')->update(['meta_value' => '']);
        $this->artisan('cache:clear');
        $this->getJson('/api/recipes/carbonara')->assertJsonPath('data.youtubeId', null);
    }

    public function test_a_recipe_without_a_featured_image_gets_the_first_gallery_photo(): void
    {
        [$first, $second, $own] = [$this->makePost('attachment', 'first'), $this->makePost('attachment', 'second'), $this->makePost('attachment', 'own')];
        $gallery = serialize([(string) $first, (string) $second]);
        $bare = $this->recipe('carbonara', [], ['gallery' => $gallery]);
        $covered = $this->recipe('plov', [], ['gallery' => $gallery, '_thumbnail_id' => (string) $own]);
        $broken = $this->recipe('borscht', [], ['gallery' => serialize(['999999'])]);

        $this->artisan('recipes:move-legacy-fields')->assertSuccessful();
        $this->artisan('recipes:move-legacy-fields')->assertSuccessful();

        $this->assertSame((string) $first, $this->postMeta($bare, '_thumbnail_id'));
        $this->assertSame(1, $this->site()->table('postmeta')->where('post_id', $bare)->where('meta_key', '_thumbnail_id')->count());
        $this->assertSame((string) $own, $this->postMeta($covered, '_thumbnail_id'));
        // A photo that is gone from the media library is not set.
        $this->assertNull($this->postMeta($broken, '_thumbnail_id'));
        // The gallery stays as it was.
        $this->assertSame($gallery, $this->postMeta($bare, 'gallery'));
    }
}
