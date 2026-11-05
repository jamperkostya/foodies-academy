<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Comments of WordPress posts (recipes): approved ones to show, new ones saved for moderation. */
class Comments
{
    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    /**
     * Approved comments of a post as Astro Review objects: threads newest first, each
     * followed by its replies (any depth) in the order they were written. A reply carries
     * "replyTo" — the name of the author it answers.
     */
    public function getForPost(int $postId): array
    {
        $rows = $this->db->table('comments')
            ->where('comment_post_ID', $postId)
            ->where('comment_approved', '1')
            ->whereIn('comment_type', ['comment', ''])
            ->orderByDesc('comment_date')
            ->get(['comment_ID', 'comment_parent', 'comment_author', 'comment_date', 'comment_content'])
            ->keyBy('comment_ID');
        $ratings = $this->db->table('commentmeta')
            ->whereIn('comment_id', $rows->keys())
            ->where('meta_key', 'rating')
            ->pluck('meta_value', 'comment_id');

        // A reply whose parent is not shown (pending, spam, deleted) becomes a thread of its own.
        $isReply = fn ($row) => $row->comment_parent && $rows->has($row->comment_parent);
        $replies = $rows->filter($isReply)->reverse()->groupBy('comment_parent');

        $ordered = [];
        $walk = function ($row) use (&$walk, &$ordered, $replies) {
            $ordered[] = $row;
            foreach ($replies->get($row->comment_ID, []) as $reply) {
                $walk($reply);
            }
        };
        $rows->reject($isReply)->each($walk);

        $name = fn ($row) => html_entity_decode($row->comment_author, ENT_QUOTES | ENT_HTML5);

        return collect($ordered)->map(fn ($row) => array_filter([
            'name' => $name($row),
            'date' => Carbon::parse($row->comment_date)->locale('ru')->translatedFormat('j F Y'),
            'text' => trim(html_entity_decode(strip_tags($row->comment_content), ENT_QUOTES | ENT_HTML5)),
            'rating' => isset($ratings[$row->comment_ID]) ? (int) $ratings[$row->comment_ID] : null,
            'replyTo' => $isReply($row) ? $name($rows[$row->comment_parent]) : null,
        ], fn ($value) => $value !== null))->values()->all();
    }

    /**
     * Approved comments per post: post_id => count. Counted here rather than read from
     * posts.comment_count, which WordPress only updates when a comment changes through
     * WordPress itself (not when it is approved straight in the database).
     */
    public function counts(array $postIds): array
    {
        if (! $postIds) {
            return [];
        }

        return $this->db->table('comments')
            ->whereIn('comment_post_ID', $postIds)
            ->where('comment_approved', '1')
            ->whereIn('comment_type', ['comment', ''])
            ->groupBy('comment_post_ID')
            ->selectRaw('comment_post_ID as post_id, COUNT(*) as count')
            ->pluck('count', 'post_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Star rating of recipes from their approved reviews: post_id => ['average' => 4.3, 'count' => 12].
     * Recipes without rated reviews are left out.
     */
    public function ratings(array $postIds): array
    {
        if (! $postIds) {
            return [];
        }

        // Raw SQL needs the prefixed alias ("artit_m"); the query builder adds it everywhere else.
        $stars = 'CAST('.$this->db->getTablePrefix().'m.meta_value AS UNSIGNED)';

        return $this->db->table('comments as c')
            ->join('commentmeta as m', function ($join) {
                $join->on('m.comment_id', '=', 'c.comment_ID')->where('m.meta_key', 'rating');
            })
            ->whereIn('c.comment_post_ID', $postIds)
            ->where('c.comment_approved', '1')
            ->whereBetween(DB::raw($stars), [1, 5])
            ->groupBy('c.comment_post_ID')
            ->get(['c.comment_post_ID as post_id', DB::raw("AVG($stars) as average"), DB::raw('COUNT(*) as count')])
            ->mapWithKeys(fn ($row) => [(int) $row->post_id => ['average' => round((float) $row->average, 1), 'count' => (int) $row->count]])
            ->all();
    }

    /**
     * Saves a visitor's comment as pending (comment_approved = 0): it shows up on the site
     * only after a moderator approves it in the WordPress admin.
     */
    public function addPending(int $postId, string $name, string $text, ?int $rating, string $ip, string $agent): void
    {
        // WordPress stores local time next to GMT; the site's offset is in the gmt_offset option.
        $offset = (float) ($this->db->table('options')->where('option_name', 'gmt_offset')->value('option_value') ?? 0);
        $gmt = Carbon::now('UTC');

        $id = $this->db->table('comments')->insertGetId([
            'comment_post_ID' => $postId,
            'comment_author' => $name,
            'comment_author_email' => '',
            'comment_author_url' => '',
            'comment_author_IP' => $ip,
            'comment_date' => $gmt->copy()->addMinutes((int) round($offset * 60))->format('Y-m-d H:i:s'),
            'comment_date_gmt' => $gmt->format('Y-m-d H:i:s'),
            'comment_content' => $text,
            'comment_karma' => 0,
            'comment_approved' => '0',
            'comment_agent' => mb_substr($agent, 0, 255),
            'comment_type' => 'comment',
            'comment_parent' => 0,
            'user_id' => 0,
        ], 'comment_ID');

        if ($rating) {
            $this->db->table('commentmeta')->insert(['comment_id' => $id, 'meta_key' => 'rating', 'meta_value' => (string) $rating]);
        }
    }
}
