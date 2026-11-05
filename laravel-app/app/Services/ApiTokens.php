<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Log-in tokens: a random string for the browser, its sha256 in the api_tokens table. */
class ApiTokens
{
    public function create(int $wpUserId): string
    {
        $token = Str::random(64);
        DB::table('api_tokens')->insert([
            'wp_user_id' => $wpUserId,
            'token_hash' => hash('sha256', $token),
            'last_used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    /** WordPress user id of a token, or null. Tokens unused for 90 days expire. */
    public function userId(string $token): ?int
    {
        $row = DB::table('api_tokens')->where('token_hash', hash('sha256', $token))->first();
        if (! $row || ($row->last_used_at && now()->subDays(90)->gt($row->last_used_at))) {
            return null;
        }
        // Once an hour is enough to keep it alive.
        if (! $row->last_used_at || now()->subHour()->gt($row->last_used_at)) {
            DB::table('api_tokens')->where('id', $row->id)->update(['last_used_at' => now()]);
        }

        return (int) $row->wp_user_id;
    }

    public function delete(string $token): void
    {
        DB::table('api_tokens')->where('token_hash', hash('sha256', $token))->delete();
    }
}
