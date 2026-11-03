<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\SiteTestCase;

class VideosTest extends SiteTestCase
{
    private function feed(int $views): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">
 <entry>
  <yt:videoId>abc123</yt:videoId>
  <title>Спагетти Том Ям☆ Рецепт от ОЛЕГА БАЖЕНОВА #101 [FOODIES.ACADEMY]</title>
  <published>2022-04-01T06:00:00+00:00</published>
  <media:group>
   <media:title>Спагетти Том Ям</media:title>
   <media:thumbnail url="https://i2.ytimg.com/vi/abc123/hqdefault.jpg" width="480" height="360"/>
   <media:description>Ингредиенты:
Креветки 15 шт

Подробнее: https://foodies.academy/recipe/spagetti-tom-jam</media:description>
   <media:community>
    <media:starRating count="76" average="5.00" min="1" max="5"/>
    <media:statistics views="$views"/>
   </media:community>
  </media:group>
 </entry>
</feed>
XML;
    }

    /** The feed answers with these view counts, one per sync. */
    private function fakeYouTube(int ...$views): void
    {
        $feed = Http::sequence();
        foreach ($views as $count) {
            $feed->push($this->feed($count));
        }
        Http::fake([
            'www.youtube.com/feeds/*' => $feed,
            'i.ytimg.com/vi/abc123/maxresdefault.jpg' => Http::response('', 200),
        ]);
    }

    public function test_sync_imports_new_videos_and_refreshes_statistics(): void
    {
        $this->fakeYouTube(3752, 4000);
        $this->artisan('videos:sync')->assertSuccessful();

        $video = $this->site()->table('posts')->where('post_type', 'video')->first();
        $this->assertSame('Спагетти Том Ям', $video->post_title);
        $this->assertSame('spagetti-tom-yam', $video->post_name);
        $this->assertSame('2022-04-01 08:00:00', $video->post_date); // 06:00 UTC in site time, gmt_offset 2
        $this->assertStringContainsString('<a href="https://foodies.academy/recipe/spagetti-tom-jam"', $video->post_content);
        $this->assertSame('3752', $this->postMeta($video->ID, 'youtube_views'));
        $this->assertSame('76', $this->postMeta($video->ID, 'youtube_likes'));
        $this->assertSame('https://i.ytimg.com/vi/abc123/maxresdefault.jpg', $this->postMeta($video->ID, 'youtube_thumbnail'));

        // An editor renames it in WordPress; the next sync only refreshes the numbers.
        $this->site()->table('posts')->where('ID', $video->ID)->update(['post_title' => 'Том Ям со спагетти']);
        $this->artisan('videos:sync')->assertSuccessful();

        $this->assertSame(1, $this->site()->table('posts')->where('post_type', 'video')->count());
        $this->assertSame('Том Ям со спагетти', $this->site()->table('posts')->where('ID', $video->ID)->value('post_title'));
        $this->assertSame('4000', $this->postMeta($video->ID, 'youtube_views'));
    }

    public function test_thumbnail_falls_back_when_youtube_has_no_maxres(): void
    {
        Http::fake([
            'www.youtube.com/feeds/*' => Http::response($this->feed(1)),
            'i.ytimg.com/*' => Http::response('', 404),
        ]);
        $this->artisan('videos:sync');

        $id = $this->site()->table('posts')->where('post_type', 'video')->value('ID');
        $this->assertSame('https://i.ytimg.com/vi/abc123/mqdefault.jpg', $this->postMeta($id, 'youtube_thumbnail'));
    }

    public function test_the_whole_channel_through_the_data_api(): void
    {
        config(['services.youtube.key' => 'key']);
        Http::fake([
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU1']]]]]),
            'www.googleapis.com/youtube/v3/playlistItems*' => Http::sequence()
                ->push(['items' => [['contentDetails' => ['videoId' => 'v1']]], 'nextPageToken' => 'p2'])
                ->push(['items' => [['contentDetails' => ['videoId' => 'v2']]]]),
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [
                ['id' => 'v1', 'snippet' => ['title' => 'Борщ', 'description' => '', 'publishedAt' => '2020-01-01T00:00:00Z', 'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/v1.jpg']]], 'statistics' => ['viewCount' => '10', 'likeCount' => '2', 'commentCount' => '1'], 'contentDetails' => ['duration' => 'PT1H2M5S']],
                ['id' => 'v2', 'snippet' => ['title' => 'Паста', 'description' => '', 'publishedAt' => '2021-01-01T00:00:00Z', 'thumbnails' => []], 'statistics' => [], 'contentDetails' => ['duration' => 'PT12M40S']],
            ]]),
        ]);

        $this->artisan('videos:sync')->expectsOutputToContain('2 added')->assertSuccessful();

        $this->getJson('/api/videos')
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.videos.0.title', 'Паста')
            ->assertJsonPath('data.videos.0.duration', '12:40')
            ->assertJsonPath('data.videos.1.duration', '1:02:05');
    }

    public function test_video_api(): void
    {
        $id = $this->makePost('video', 'tom-yam', ['post_content' => '<p>Описание</p>'], [
            'youtube_id' => 'abc', 'youtube_views' => '3752', 'youtube_likes' => '76', 'youtube_published_at' => '2022-04-01T06:00:00+00:00',
            'pld_like_count' => '4', 'pld_dislike_count' => '1',
        ]);
        $this->comment($id, 'Анна', [], 5);

        $this->getJson('/api/videos/slugs')->assertJsonPath('data', ['tom-yam']);
        $this->getJson('/api/videos/tom-yam')
            ->assertOk()
            ->assertJsonPath('data.youtubeId', 'abc')
            ->assertJsonPath('data.views', 3752)
            ->assertJsonPath('data.likes', 3)
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.date', '1 апреля 2022')
            ->assertJsonPath('data.reviews.0.name', 'Анна');
        $this->getJson('/api/videos/nope')->assertNotFound();
    }
}
