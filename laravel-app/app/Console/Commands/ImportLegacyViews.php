<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Copies the old views plugin's numbers (post_views_realtime / post_views_history, people only,
 * full pages) into Post Views Counter's table post_views, so the WordPress admin shows them:
 * the total of every post, and its views per year and per month from the history.
 * Days and weeks are left out (millions of rows; the old plugin numbered weeks its own way).
 * Safe to re-run: rows are overwritten with the same numbers, nothing is added twice.
 */
class ImportLegacyViews extends Command
{
    protected $signature = 'views:import-legacy';

    protected $description = 'Copy old post views (totals, years, months) into Post Views Counter';

    public function handle(): int
    {
        $db = DB::connection('site');
        $table = $db->getTablePrefix().'post_views';
        $rows = [];

        // Totals: the old counter keeps counting (App\Services\PostViews writes to both), so it already
        // includes the views counted since Post Views Counter was installed.
        $totals = $db->table('post_views_realtime')
            ->where('view_type', 'normal')->where('output_type', 'content')->where('post_views_total', '>', 0)
            ->pluck('post_views_total', 'post_id');
        foreach ($totals as $postId => $total) {
            $rows[] = [(int) $postId, 4, 'total', (int) $total];
        }

        // History: per year, "-1,<jan>,<feb>…" for months and "-1,<year>" for the year.
        $history = $db->table('post_views_history')
            ->where('view_type', 'normal')->where('output_type', 'content')
            ->get(['year_id', 'post_id', 'post_views_month', 'post_views_year']);
        foreach ($history as $row) {
            $months = array_slice(explode(',', $row->post_views_month), 1);
            foreach ($months as $i => $count) {
                if ((int) $count > 0) {
                    $rows[] = [(int) $row->post_id, 2, sprintf('%d%02d', $row->year_id, $i + 1), (int) $count];
                }
            }
            $year = (int) (explode(',', $row->post_views_year)[1] ?? 0);
            if ($year > 0) {
                $rows[] = [(int) $row->post_id, 3, (string) $row->year_id, $year];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $db->statement(
                "INSERT INTO `$table` (`id`, `type`, `period`, `count`) VALUES ".implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?)')).'
                ON DUPLICATE KEY UPDATE `count` = VALUES(`count`)',
                array_merge(...$chunk),
            );
        }

        $this->info(sprintf('Post Views Counter: %d totals, %d year and month rows.', $totals->count(), count($rows) - $totals->count()));

        return self::SUCCESS;
    }
}
