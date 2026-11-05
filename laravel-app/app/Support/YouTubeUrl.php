<?php

namespace App\Support;

class YouTubeUrl
{
    /** Video ID from a watch / youtu.be / embed / shorts link, null for anything else. */
    public static function id(string $url): ?string
    {
        return preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/))([A-Za-z0-9_-]{11})~', $url, $m) ? $m[1] : null;
    }
}
