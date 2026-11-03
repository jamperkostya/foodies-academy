<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Visitors' star rating of an author, kept in the author's usermeta
 * ("foodies_rating_sum" and "foodies_rating_count"), so it is visible to WordPress too.
 */
class AuthorRatings
{
    private const SUM = 'foodies_rating_sum';
    private const COUNT = 'foodies_rating_count';

    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    /** {average, count}; average is null while nobody has rated. */
    public function get(int $userId): array
    {
        $meta = $this->db->table('usermeta')
            ->where('user_id', $userId)
            ->whereIn('meta_key', [self::SUM, self::COUNT])
            ->pluck('meta_value', 'meta_key');
        $count = max(0, (int) ($meta[self::COUNT] ?? 0));
        $sum = max(0, (int) ($meta[self::SUM] ?? 0));

        return ['average' => $count ? round($sum / $count, 1) : null, 'count' => $count];
    }

    /** Replaces a visitor's previous stars ($from, null for a first vote) with $to (null to take it back). */
    public function vote(int $userId, ?int $from, ?int $to): array
    {
        $this->db->transaction(function () use ($userId, $from, $to) {
            $this->add($userId, self::SUM, (int) $to - (int) $from);
            $this->add($userId, self::COUNT, ($to ? 1 : 0) - ($from ? 1 : 0));
        });

        return $this->get($userId);
    }

    private function add(int $userId, string $key, int $delta): void
    {
        $row = $this->db->table('usermeta')
            ->where('user_id', $userId)
            ->where('meta_key', $key)
            ->lockForUpdate()
            ->first(['umeta_id', 'meta_value']);
        $value = (string) max(0, (int) ($row->meta_value ?? 0) + $delta);

        if ($row) {
            $this->db->table('usermeta')->where('umeta_id', $row->umeta_id)->update(['meta_value' => $value]);
        } else {
            $this->db->table('usermeta')->insert(['user_id' => $userId, 'meta_key' => $key, 'meta_value' => $value]);
        }
    }
}
