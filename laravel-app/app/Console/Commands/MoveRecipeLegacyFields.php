<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Puts what the old theme kept in its ACF fields (not editable without ACF) where the recipe
 * screen in the admin shows it:
 *  - the video link ("video") goes to WP Ultimate Recipe's "Recipe Video" (recipe_video_embed),
 *    and the old field is deleted: left in place, it would bring back a video cleared in the admin;
 *  - a recipe without a featured image gets the first photo of its gallery, the cover the site
 *    shows. The gallery itself stays: the mu-plugin foodies-recipe-gallery.php edits it.
 * What is already filled in keeps its value. Safe to re-run.
 */
class MoveRecipeLegacyFields extends Command
{
    protected $signature = 'recipes:move-legacy-fields';

    protected $description = 'Move recipe videos and covers from the old theme fields to the ones the admin shows';

    private ConnectionInterface $db;

    public function handle(): int
    {
        $this->db = DB::connection('site');
        $this->moveVideos();
        $this->setCovers();
        $this->call('cache:clear');

        return self::SUCCESS;
    }

    private function moveVideos(): void
    {
        $old = $this->recipeMeta('video')->pluck('m.meta_value', 'm.post_id');
        $moved = 0;

        foreach ($old as $postId => $link) {
            $this->db->transaction(function () use ($postId, $link, &$moved) {
                $embed = $this->db->table('postmeta')->where('post_id', $postId)->where('meta_key', 'recipe_video_embed');
                if (trim($link) !== '' && trim((string) $embed->clone()->value('meta_value')) === '') {
                    $embed->clone()->delete();
                    $this->db->table('postmeta')->insert(['post_id' => $postId, 'meta_key' => 'recipe_video_embed', 'meta_value' => trim($link)]);
                    $moved++;
                }
                // "_video" is ACF's reference to the field's definition.
                $this->db->table('postmeta')->where('post_id', $postId)->whereIn('meta_key', ['video', '_video'])->delete();
            });
        }

        $this->info("Moved the video links of $moved recipes, removed the old field from {$old->count()}.");
    }

    private function setCovers(): void
    {
        $withCover = $this->recipeMeta('_thumbnail_id')->where('m.meta_value', '>', 0)->pluck('m.post_id')->flip();
        $set = 0;

        foreach ($this->recipeMeta('gallery')->pluck('m.meta_value', 'm.post_id') as $postId => $gallery) {
            $ids = @unserialize($gallery, ['allowed_classes' => false]);
            $first = is_array($ids) ? (int) reset($ids) : 0;
            if ($withCover->has($postId) || ! $first || ! $this->db->table('posts')->where('ID', $first)->where('post_type', 'attachment')->exists()) {
                continue;
            }
            $this->db->transaction(function () use ($postId, $first) {
                $this->db->table('postmeta')->where('post_id', $postId)->where('meta_key', '_thumbnail_id')->delete();
                $this->db->table('postmeta')->insert(['post_id' => $postId, 'meta_key' => '_thumbnail_id', 'meta_value' => (string) $first]);
            });
            $set++;
        }

        $this->info("Set the first gallery photo as the featured image of $set recipes.");
    }

    private function recipeMeta(string $key)
    {
        return $this->db->table('postmeta as m')
            ->join('posts as p', 'p.ID', '=', 'm.post_id')
            ->where('p.post_type', 'recipe')
            ->where('m.meta_key', $key);
    }
}
