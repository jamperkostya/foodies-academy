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

    /** Approved comments of a post, newest first, as Astro Review objects. */
    public function getForPost(int $postId): array
    {
        $rows = $this->db->table('comments')
            ->where('comment_post_ID', $postId)
            ->where('comment_approved', '1')
            ->whereIn('comment_type', ['comment', ''])
            ->orderByDesc('comment_date')
            ->get(['comment_ID', 'comment_author', 'comment_date', 'comment_content']);
        $ratings = $this->db->table('commentmeta')
            ->whereIn('comment_id', $rows->pluck('comment_ID'))
            ->where('meta_key', 'rating')
            ->pluck('meta_value', 'comment_id');

        return $rows->map(fn ($row) => array_filter([
            'name' => html_entity_decode($row->comment_author, ENT_QUOTES | ENT_HTML5),
            'date' => Carbon::parse($row->comment_date)->locale('ru')->translatedFormat('j F Y'),
            'text' => trim(html_entity_decode(strip_tags($row->comment_content), ENT_QUOTES | ENT_HTML5)),
            'rating' => isset($ratings[$row->comment_ID]) ? (int) $ratings[$row->comment_ID] : null,
        ], fn ($value) => $value !== null))->values()->all();
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
