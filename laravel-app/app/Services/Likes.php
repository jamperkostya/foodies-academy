<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Likes and dislikes of a post, stored where the Posts Like Dislike plugin keeps them
 * (postmeta "pld_like_count" / "pld_dislike_count"), so the WordPress admin shows the same numbers.
 * A recipe has one count in all languages: a vote is written to every translation of the post.
 */
class Likes
{
    private const KEYS = ['like' => 'pld_like_count', 'dislike' => 'pld_dislike_count'];

    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    /** {like, dislike} of a post. */
    public function get(int $postId): array
    {
        $meta = $this->db->table('postmeta')
            ->where('post_id', $postId)
            ->whereIn('meta_key', self::KEYS)
            ->pluck('meta_value', 'meta_key');

        return array_map(fn ($key) => max(0, (int) ($meta[$key] ?? 0)), self::KEYS);
    }

    /**
     * Moves a visitor's vote: takes one off $from (their previous vote) and adds one to $to.
     * Either may be null: a first vote has no $from, an undo has no $to. Returns the new counts.
     */
    public function vote(int $postId, ?string $from, ?string $to): array
    {
        $ids = app(Translations::class)->group($postId);
        $this->db->transaction(function () use ($ids, $from, $to) {
            foreach ($ids as $id) {
                if ($from) {
                    $this->add($id, self::KEYS[$from], -1);
                }
                if ($to) {
                    $this->add($id, self::KEYS[$to], 1);
                }
            }
        });

        return $this->get($postId);
    }

    private function add(int $postId, string $key, int $delta): void
    {
        $row = $this->db->table('postmeta')
            ->where('post_id', $postId)
            ->where('meta_key', $key)
            ->lockForUpdate()
            ->first(['meta_id', 'meta_value']);

        $value = max(0, (int) ($row->meta_value ?? 0) + $delta);
        if ($row) {
            $this->db->table('postmeta')->where('meta_id', $row->meta_id)->update(['meta_value' => (string) $value]);
        } else {
            $this->db->table('postmeta')->insert(['post_id' => $postId, 'meta_key' => $key, 'meta_value' => (string) $value]);
        }
    }
}
