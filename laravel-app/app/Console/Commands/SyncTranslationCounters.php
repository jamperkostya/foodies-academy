<?php

namespace App\Console\Commands;

use App\Services\Translations;
use App\Support\Lang;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives every translation the likes, dislikes and views of its Russian post. From then on the
 * site writes a vote or a view to all translations at once (App\Services\Likes, PostViews), so
 * this is for new translations: run it after an import. Safe to re-run.
 */
class SyncTranslationCounters extends Command
{
    protected $signature = 'translations:sync-counters';

    protected $description = 'Copy likes and views of Russian posts to their translations';

    private const META = ['pld_like_count', 'pld_dislike_count'];

    public function handle(): int
    {
        $db = DB::connection('site');
        $prefix = $db->getTablePrefix();
        $groups = $db->table('term_taxonomy')->where('taxonomy', 'post_translations')->pluck('description');
        $synced = 0;

        foreach ($groups as $value) {
            $group = @unserialize((string) $value, ['allowed_classes' => false]);
            $source = (int) ($group[Lang::DEFAULT] ?? 0);
            $translations = is_array($group) ? array_map('intval', array_values(array_diff_key($group, [Lang::DEFAULT => 0]))) : [];
            if (! $source || ! $translations) {
                continue;
            }
            // No transaction: the tables mix engines (post_views_realtime is MyISAM, some sites
            // still have MyISAM postmeta), which MySQL refuses in one; a re-run repairs a half-done copy.
            $meta = $db->table('postmeta')->where('post_id', $source)->whereIn('meta_key', self::META)->pluck('meta_value', 'meta_key');
            $db->table('postmeta')->whereIn('post_id', $translations)->whereIn('meta_key', self::META)->delete();
            $db->table('post_views')->whereIn('id', $translations)->delete();
            $db->table('post_views_realtime')->whereIn('post_id', $translations)->delete();
            foreach ($translations as $id) {
                foreach ($meta as $key => $count) {
                    $db->table('postmeta')->insert(['post_id' => $id, 'meta_key' => $key, 'meta_value' => $count]);
                }
                $db->statement("INSERT INTO `{$prefix}post_views` (`id`, `type`, `period`, `count`) SELECT ?, `type`, `period`, `count` FROM `{$prefix}post_views` WHERE `id` = ?", [$id, $source]);
                $db->statement(
                    "INSERT INTO `{$prefix}post_views_realtime` (`post_id`, `view_type`, `output_type`, `post_views_today`, `post_views_week`,
                        `post_views_month`, `post_views_halfyear`, `post_views_year`, `post_views_total`, `latest_view_time`)
                    SELECT ?, `view_type`, `output_type`, `post_views_today`, `post_views_week`, `post_views_month`, `post_views_halfyear`,
                        `post_views_year`, `post_views_total`, `latest_view_time` FROM `{$prefix}post_views_realtime` WHERE `post_id` = ?",
                    [$id, $source],
                );
            }
            $synced += count($translations);
        }

        $this->info("Likes and views copied to $synced translations.");

        return self::SUCCESS;
    }
}
