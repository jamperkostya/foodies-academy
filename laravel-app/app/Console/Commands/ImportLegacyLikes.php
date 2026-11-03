<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off: adds the old theme's like counters (postmeta like_post / unlike_post) to the
 * Posts Like Dislike plugin's (pld_like_count / pld_dislike_count). The old keys stay as they
 * are; posts already imported are marked with pld_legacy_imported, so a second run changes nothing.
 */
class ImportLegacyLikes extends Command
{
    protected $signature = 'likes:import-legacy';

    protected $description = 'Copy like_post/unlike_post into the Posts Like Dislike plugin counters';

    private const MAP = ['like_post' => 'pld_like_count', 'unlike_post' => 'pld_dislike_count'];

    private const MARK = 'pld_legacy_imported';

    public function handle(): int
    {
        $db = DB::connection('site');
        $done = $db->table('postmeta')->where('meta_key', self::MARK)->pluck('post_id')->flip();
        $legacy = $db->table('postmeta')
            ->whereIn('meta_key', array_keys(self::MAP))
            ->get(['post_id', 'meta_key', 'meta_value'])
            ->groupBy('post_id')
            ->reject(fn ($rows, $postId) => $done->has($postId));

        foreach ($legacy as $postId => $rows) {
            $db->transaction(function () use ($db, $postId, $rows) {
                foreach ($rows as $row) {
                    $add = max(0, (int) $row->meta_value);
                    $key = self::MAP[$row->meta_key];
                    $current = $db->table('postmeta')->where('post_id', $postId)->where('meta_key', $key)->lockForUpdate()->first(['meta_id', 'meta_value']);
                    if ($current) {
                        $db->table('postmeta')->where('meta_id', $current->meta_id)->update(['meta_value' => (string) ((int) $current->meta_value + $add)]);
                    } else {
                        $db->table('postmeta')->insert(['post_id' => $postId, 'meta_key' => $key, 'meta_value' => (string) $add]);
                    }
                }
                $db->table('postmeta')->insert(['post_id' => $postId, 'meta_key' => self::MARK, 'meta_value' => '1']);
            });
        }

        $this->info("Imported likes of {$legacy->count()} posts, skipped {$done->count()} imported before.");

        return self::SUCCESS;
    }
}
