<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Site searches saved the way the Search Meter plugin saves WordPress searches
 * (tables searchmeter_recent and searchmeter), so they show up in its statistics.
 */
class SearchLog
{
    // Search Meter keeps the last 500 searches, trimming the table every 100 extra rows.
    private const HISTORY_SIZE = 500;

    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    public function record(string $search, int $hits): void
    {
        // Search Meter's columns are varchar(50).
        $search = mb_substr($search, 0, 50);
        $terms = trim(preg_replace('/[," ]+/u', ' ', $search));

        $this->db->table('searchmeter_recent')->insert([
            'terms' => $search,
            'datetime' => $this->db->raw('UTC_TIMESTAMP()'),
            'hits' => $hits,
            'details' => '',
        ]);
        $this->trimHistory();

        $prefix = $this->db->getTablePrefix();
        $this->db->statement(
            "INSERT INTO `{$prefix}searchmeter` (`terms`, `date`, `count`, `last_hits`)
            VALUES (?, UTC_DATE(), 1, ?)
            ON DUPLICATE KEY UPDATE `count` = `count` + 1, `last_hits` = VALUES(`last_hits`)",
            [$terms, $hits],
        );
    }

    private function trimHistory(): void
    {
        if ($this->db->table('searchmeter_recent')->count() <= self::HISTORY_SIZE + 100) {
            return;
        }
        $oldest = $this->db->table('searchmeter_recent')
            ->orderByDesc('datetime')
            ->offset(self::HISTORY_SIZE)
            ->value('datetime');
        $this->db->table('searchmeter_recent')->where('datetime', '<', $oldest)->delete();
    }
}
