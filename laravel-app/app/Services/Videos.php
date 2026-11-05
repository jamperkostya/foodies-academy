<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Video recipes: WordPress posts of type "video" (wp-content/mu-plugins/foodies-video.php), imported from YouTube. */
class Videos
{
    private const META = ['youtube_id', 'youtube_title', 'youtube_thumbnail', 'youtube_published_at', 'youtube_duration', 'youtube_views', 'youtube_likes', 'youtube_comments', 'pld_like_count', 'pld_dislike_count'];

    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    /**
     * Adds the YouTube videos that aren't in WordPress yet and refreshes the statistics of the rest.
     * Titles and descriptions edited in the WordPress admin are kept. Returns [added, updated].
     */
    public function sync(array $videos): array
    {
        $existing = $this->db->table('postmeta as m')
            ->join('posts as p', 'p.ID', '=', 'm.post_id')
            ->where('p.post_type', 'video')
            ->where('m.meta_key', 'youtube_id')
            ->pluck('m.post_id', 'm.meta_value');
        $added = $updated = 0;

        foreach ($videos as $video) {
            $postId = $existing[$video['id']] ?? null;
            $this->db->transaction(function () use ($video, &$postId, &$added, &$updated) {
                if (! $postId) {
                    $postId = $this->insertPost($video);
                    $added++;
                } else {
                    $updated++;
                }
                $this->setMeta($postId, array_filter([
                    'youtube_id' => $video['id'],
                    'youtube_title' => $video['title'],
                    'youtube_thumbnail' => $video['thumbnail'],
                    'youtube_published_at' => $video['publishedAt']->toIso8601String(),
                    // The RSS feed has no duration or comment count: keep what the API saved before.
                    'youtube_duration' => $video['duration'] ?: null,
                    'youtube_views' => (string) $video['views'],
                    'youtube_likes' => (string) $video['likes'],
                    'youtube_comments' => $video['comments'] === null ? null : (string) $video['comments'],
                    'youtube_synced_at' => now()->toIso8601String(),
                ], fn ($value) => $value !== null));
            });
        }

        return [$added, $updated];
    }

    /** One page of published videos, newest first: {videos, total, page, pages}. */
    public function getPage(int $page = 1, int $perPage = 24): array
    {
        $query = $this->published();
        $total = (clone $query)->count();
        $posts = $query->orderByDesc('post_date')->forPage($page, $perPage)->get();

        return ['videos' => $this->present($posts), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public function getSlugs(): array
    {
        return $this->published()->orderByDesc('post_date')->pluck('post_name')->all();
    }

    public function getId(string $slug): ?int
    {
        $id = $this->published()->where('post_name', $slug)->value('ID');

        return $id ? (int) $id : null;
    }

    /** A video with its description (HTML), reviews, the site's likes and SEO text, or null. */
    public function getBySlug(string $slug): ?array
    {
        $post = $this->published()->where('post_name', $slug)->first();
        if (! $post) {
            return null;
        }

        return $this->present(collect([$post]))[0] + [
            'description' => $post->post_content,
            'reviews' => app(Comments::class)->getForPost($post->ID),
            'votes' => app(Likes::class)->get($post->ID),
            'seoText' => app(SeoText::class)->forPost($post->ID),
            'seo' => app(Seo::class)->forPost('video', $post->post_name),
        ];
    }

    private function published()
    {
        return $this->db->table('posts')->where('post_type', 'video')->where('post_status', 'publish');
    }

    private function present(Collection $posts): array
    {
        $meta = $this->db->table('postmeta')
            ->whereIn('post_id', $posts->pluck('ID'))
            ->whereIn('meta_key', self::META)
            ->get(['post_id', 'meta_key', 'meta_value'])
            ->groupBy('post_id')
            ->map(fn ($rows) => $rows->pluck('meta_value', 'meta_key'));
        $ratings = app(Comments::class)->ratings($posts->pluck('ID')->all());

        return $posts->map(function ($post) use ($meta, $ratings) {
            $m = $meta->get($post->ID, collect());

            return [
                'id' => (int) $post->ID,
                'slug' => $post->post_name,
                'title' => html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5),
                'youtubeId' => (string) $m->get('youtube_id', ''),
                'image' => (string) $m->get('youtube_thumbnail', ''),
                'date' => Carbon::parse($m->get('youtube_published_at', $post->post_date_gmt))->locale('ru')->translatedFormat('j F Y'),
                'publishedAt' => Carbon::parse($m->get('youtube_published_at', $post->post_date_gmt))->toIso8601String(),
                'duration' => (string) $m->get('youtube_duration', ''),
                'views' => (int) $m->get('youtube_views', 0),
                'youtubeLikes' => (int) $m->get('youtube_likes', 0),
                // The site's own likes minus dislikes (Posts Like Dislike) and comments.
                'likes' => Recipes::netLikes($m->all()),
                'comments' => (int) $post->comment_count,
                // Average stars of approved reviews, 0 while nobody has rated.
                'rating' => $ratings[$post->ID]['average'] ?? 0,
                'ratingCount' => $ratings[$post->ID]['count'] ?? 0,
            ];
        })->values()->all();
    }

    private function insertPost(array $video): int
    {
        $offset = (float) ($this->db->table('options')->where('option_name', 'gmt_offset')->value('option_value') ?? 0);
        $gmt = $video['publishedAt']->copy()->utc();
        $local = $gmt->copy()->addMinutes((int) round($offset * 60));
        $now = Carbon::now('UTC');
        $title = $this->cleanTitle($video['title']);

        $id = $this->db->table('posts')->insertGetId([
            'post_author' => config('services.youtube.author_id'),
            'post_date' => $local->format('Y-m-d H:i:s'),
            'post_date_gmt' => $gmt->format('Y-m-d H:i:s'),
            'post_content' => $this->descriptionHtml($video['description']),
            'post_title' => $title,
            'post_excerpt' => '',
            'post_status' => 'publish',
            'comment_status' => 'open',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => $this->uniqueSlug($title),
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $now->copy()->addMinutes((int) round($offset * 60))->format('Y-m-d H:i:s'),
            'post_modified_gmt' => $now->format('Y-m-d H:i:s'),
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => '',
            'menu_order' => 0,
            'post_type' => 'video',
            'post_mime_type' => '',
            'comment_count' => 0,
        ], 'ID');
        $this->db->table('posts')->where('ID', $id)->update(['guid' => rtrim((string) $this->db->table('options')->where('option_name', 'home')->value('option_value'), '/')."/?post_type=video&p=$id"]);

        return $id;
    }

    // "Щёки в вине☆ Рецепт от ОЛЕГА БАЖЕНОВА #102 [FOODIES.ACADEMY]" → "Щёки в вине"
    private function cleanTitle(string $title): string
    {
        $clean = preg_replace(['/[☆★].*$/u', '/\[[^\]]*\]/u', '/#\d+/u'], '', $title);
        $clean = trim(preg_replace('/\s+/u', ' ', $clean), " \t-–—|");

        return $clean !== '' ? $clean : $title;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title, '-', 'ru') ?: 'video';
        $base = substr($base, 0, 180);
        $slug = $base;
        for ($i = 2; $this->db->table('posts')->where('post_type', 'video')->where('post_name', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }

    // Plain YouTube description → paragraphs, links clickable.
    private function descriptionHtml(string $text): string
    {
        $paragraphs = preg_split('/\R{2,}/u', trim($text)) ?: [];

        return implode("\n", array_map(function ($paragraph) {
            $html = nl2br(e(trim($paragraph)), false);
            $html = preg_replace('~(https?://[^\s<]+)~u', '<a href="$1" rel="nofollow noopener" target="_blank">$1</a>', $html);

            return "<p>$html</p>";
        }, array_filter($paragraphs, fn ($p) => trim($p) !== '')));
    }

    private function setMeta(int $postId, array $values): void
    {
        foreach ($values as $key => $value) {
            $updated = $this->db->table('postmeta')->where('post_id', $postId)->where('meta_key', $key)->update(['meta_value' => $value]);
            if (! $updated && ! $this->db->table('postmeta')->where('post_id', $postId)->where('meta_key', $key)->exists()) {
                $this->db->table('postmeta')->insert(['post_id' => $postId, 'meta_key' => $key, 'meta_value' => $value]);
            }
        }
    }
}
