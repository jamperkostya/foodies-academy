<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Counts a view of a page of a post (the site is static, so the browser reports it).
 * Written twice:
 *  - post_views_counter's table post_views (day/week/month/year/total rows), so the WordPress
 *    admin shows the views;
 *  - post_views_realtime (normal/content), the table the site's own view numbers come from.
 * A recipe has one count in all languages: a view is written to every translation of the post.
 */
class PostViews
{
    // The API's name of a kind of post => its WordPress post type.
    public const TYPES = ['recipes' => 'recipe', 'videos' => 'video', 'lifehacks' => 'lifehack', 'news' => 'news', 'blog' => 'post', 'pages' => 'page'];

    // Search engines and other bots: Post Views Counter doesn't count them either.
    private const BOT = '/bot|crawl|spider|slurp|facebookexternalhit|preview|headless|lighthouse|curl|wget|python|http-client|monitor/i';

    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    public static function isBot(string $userAgent): bool
    {
        return $userAgent === '' || (bool) preg_match(self::BOT, $userAgent);
    }

    /** The published post of an API kind (blog, pages…) with $slug, or null. Recipes: Recipes::getRecipeId(). */
    public function postId(string $type, string $slug): ?int
    {
        $id = $this->db->table('posts')->where('post_type', self::TYPES[$type])->where('post_status', 'publish')->where('post_name', $slug)->value('ID');

        return $id ? (int) $id : null;
    }

    /** Views of the posts on the site: post_id => total. */
    public function totals(array $ids): array
    {
        return array_map('intval', $this->db->table('post_views_realtime')
            ->whereIn('post_id', $ids)
            ->where('view_type', 'normal')
            ->where('output_type', 'content')
            ->pluck('post_views_total', 'post_id')
            ->all());
    }

    public function count(int $postId): void
    {
        // Post Views Counter uses the site's local time for its periods.
        $offset = (float) ($this->db->table('options')->where('option_name', 'gmt_offset')->value('option_value') ?? 0);
        $now = Carbon::now('UTC')->addMinutes((int) round($offset * 60));
        $prefix = $this->db->getTablePrefix();

        // type => period: 0 day "20261103", 1 ISO week "202645", 2 month "202611", 3 year "2026", 4 "total".
        $periods = [0 => $now->format('Ymd'), 1 => $now->format('oW'), 2 => $now->format('Ym'), 3 => $now->format('Y'), 4 => 'total'];
        $ids = array_values(app(Translations::class)->group($postId));
        $rows = implode(', ', array_fill(0, count($periods) * count($ids), '(?, ?, ?, 1)'));
        $values = [];
        foreach ($ids as $id) {
            foreach ($periods as $type => $period) {
                array_push($values, $id, $type, $period);
            }
        }
        $this->db->statement(
            "INSERT INTO `{$prefix}post_views` (`id`, `type`, `period`, `count`) VALUES $rows
            ON DUPLICATE KEY UPDATE `count` = `count` + 1",
            $values,
        );

        foreach ($ids as $id) {
            $this->db->statement(
                "INSERT INTO `{$prefix}post_views_realtime`
                    (`post_id`, `view_type`, `output_type`, `post_views_today`, `post_views_week`, `post_views_month`,
                     `post_views_halfyear`, `post_views_year`, `post_views_total`, `latest_view_time`)
                VALUES (?, 'normal', 'content', 1, 1, 1, 1, 1, 1, ?)
                ON DUPLICATE KEY UPDATE `post_views_total` = `post_views_total` + 1, `latest_view_time` = VALUES(`latest_view_time`)",
                [$id, $now->format('Y-m-d H:i:s')],
            );
        }
    }
}
