<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Counts a view of a recipe or video page (the site is static, so the browser reports it).
 * Written twice:
 *  - post_views_counter's table post_views (day/week/month/year/total rows), so the WordPress
 *    admin shows the views;
 *  - post_views_realtime (normal/content), the table the site's own view numbers come from.
 */
class PostViews
{
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

    public function count(int $postId): void
    {
        // Post Views Counter uses the site's local time for its periods.
        $offset = (float) ($this->db->table('options')->where('option_name', 'gmt_offset')->value('option_value') ?? 0);
        $now = Carbon::now('UTC')->addMinutes((int) round($offset * 60));
        $prefix = $this->db->getTablePrefix();

        // type => period: 0 day "20261103", 1 ISO week "202645", 2 month "202611", 3 year "2026", 4 "total".
        $periods = [0 => $now->format('Ymd'), 1 => $now->format('oW'), 2 => $now->format('Ym'), 3 => $now->format('Y'), 4 => 'total'];
        $rows = implode(', ', array_fill(0, count($periods), '(?, ?, ?, 1)'));
        $values = [];
        foreach ($periods as $type => $period) {
            array_push($values, $postId, $type, $period);
        }
        $this->db->statement(
            "INSERT INTO `{$prefix}post_views` (`id`, `type`, `period`, `count`) VALUES $rows
            ON DUPLICATE KEY UPDATE `count` = `count` + 1",
            $values,
        );

        $this->db->statement(
            "INSERT INTO `{$prefix}post_views_realtime`
                (`post_id`, `view_type`, `output_type`, `post_views_today`, `post_views_week`, `post_views_month`,
                 `post_views_halfyear`, `post_views_year`, `post_views_total`, `latest_view_time`)
            VALUES (?, 'normal', 'content', 1, 1, 1, 1, 1, 1, ?)
            ON DUPLICATE KEY UPDATE `post_views_total` = `post_views_total` + 1, `latest_view_time` = VALUES(`latest_view_time`)",
            [$postId, $now->format('Y-m-d H:i:s')],
        );
    }
}
