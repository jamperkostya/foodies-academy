<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

/**
 * Videos of the YouTube channel, as
 * {id, title, description, publishedAt: Carbon, thumbnail, duration: "12:40"|"", views, likes, comments: int|null}.
 * With services.youtube.key: every video through the YouTube Data API. Without it: the channel's
 * RSS feed, which only has the latest 15 videos and no duration or comment count.
 */
class YouTube
{
    private const API = 'https://www.googleapis.com/youtube/v3';

    public function hasApiKey(): bool
    {
        return (string) config('services.youtube.key') !== '';
    }

    /** All the channel's videos (Data API), or the latest 15 without a key (RSS). */
    public function videos(bool $all = true): array
    {
        return $this->hasApiKey() ? $this->fromApi($all) : $this->fromFeed();
    }

    private function fromApi(bool $all): array
    {
        $uploads = $this->api('channels', ['part' => 'contentDetails', 'id' => config('services.youtube.channel_id')])
            ['items'][0]['contentDetails']['relatedPlaylists']['uploads']
            ?? throw new RuntimeException('YouTube channel not found');

        // Newest first; without $all only the first page (50), enough to catch new uploads.
        $ids = [];
        $token = null;
        do {
            $page = $this->api('playlistItems', array_filter([
                'part' => 'contentDetails', 'playlistId' => $uploads, 'maxResults' => 50, 'pageToken' => $token,
            ]));
            foreach ($page['items'] ?? [] as $item) {
                $ids[] = $item['contentDetails']['videoId'];
            }
            $token = $page['nextPageToken'] ?? null;
        } while ($all && $token);

        $videos = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            $items = $this->api('videos', ['part' => 'snippet,statistics,contentDetails', 'id' => implode(',', $chunk)])['items'] ?? [];
            foreach ($items as $item) {
                $thumbs = $item['snippet']['thumbnails'] ?? [];
                $videos[] = [
                    'id' => $item['id'],
                    'title' => $item['snippet']['title'],
                    'description' => $item['snippet']['description'] ?? '',
                    'publishedAt' => Carbon::parse($item['snippet']['publishedAt']),
                    'thumbnail' => ($thumbs['maxres'] ?? $thumbs['standard'] ?? $thumbs['high'] ?? $thumbs['default'] ?? [])['url'] ?? '',
                    'duration' => $this->duration($item['contentDetails']['duration'] ?? ''),
                    'views' => (int) ($item['statistics']['viewCount'] ?? 0),
                    'likes' => (int) ($item['statistics']['likeCount'] ?? 0),
                    'comments' => isset($item['statistics']['commentCount']) ? (int) $item['statistics']['commentCount'] : null,
                ];
            }
        }

        return $videos;
    }

    private function fromFeed(): array
    {
        $response = Http::timeout(20)->get('https://www.youtube.com/feeds/videos.xml', ['channel_id' => config('services.youtube.channel_id')]);
        if (! $response->successful()) {
            throw new RuntimeException("YouTube feed: HTTP {$response->status()}");
        }
        $xml = new SimpleXMLElement($response->body());

        $videos = [];
        foreach ($xml->entry as $entry) {
            $yt = $entry->children('http://www.youtube.com/xml/schemas/2015');
            $media = $entry->children('http://search.yahoo.com/mrss/')->group;
            $community = $media->community;
            $videos[] = [
                'id' => (string) $yt->videoId,
                'title' => (string) $entry->title,
                'description' => (string) $media->description,
                'publishedAt' => Carbon::parse((string) $entry->published),
                'thumbnail' => $this->feedThumbnail((string) $yt->videoId),
                'duration' => '',
                'views' => (int) $community->statistics?->attributes()?->views,
                'likes' => (int) $community->starRating?->attributes()?->count,
                'comments' => null,
            ];
        }

        return $videos;
    }

    // The feed's hqdefault is 4:3 with black bars around 16:9 videos. maxresdefault (1280×720)
    // has none, but some old videos lack it; mqdefault (320×180) always exists.
    private function feedThumbnail(string $id): string
    {
        $maxres = "https://i.ytimg.com/vi/$id/maxresdefault.jpg";

        return Http::timeout(10)->head($maxres)->successful() ? $maxres : "https://i.ytimg.com/vi/$id/mqdefault.jpg";
    }

    private function api(string $resource, array $query): array
    {
        $response = Http::timeout(20)->get(self::API."/$resource", $query + ['key' => config('services.youtube.key')]);
        if (! $response->successful()) {
            throw new RuntimeException("YouTube API $resource: HTTP {$response->status()} ".$response->json('error.message'));
        }

        return $response->json();
    }

    // ISO 8601 "PT1H2M5S" → "1:02:05", "PT12M40S" → "12:40".
    private function duration(string $iso): string
    {
        if (! preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m)) {
            return '';
        }
        [$h, $min, $s] = [(int) ($m[1] ?? 0), (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)];

        return $h ? sprintf('%d:%02d:%02d', $h, $min, $s) : sprintf('%d:%02d', $min, $s);
    }
}
