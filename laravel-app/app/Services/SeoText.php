<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use App\Support\WpHtml;
use Illuminate\Support\Facades\DB;

/**
 * "SEO-текст" filled in the WordPress admin (wp-content/mu-plugins/foodies-seo-text.php):
 * HTML in post meta / term meta "seo_text", "" when empty.
 */
class SeoText
{
    private const KEY = 'seo_text';

    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    public function forPost(int $postId): string
    {
        return $this->clean($this->db->table('postmeta')->where('post_id', $postId)->where('meta_key', self::KEY)->value('meta_value'));
    }

    public function forTerm(int $termId): string
    {
        return $this->clean($this->db->table('termmeta')->where('term_id', $termId)->where('meta_key', self::KEY)->value('meta_value'));
    }

    private function clean(?string $html): string
    {
        return WpHtml::render($html);
    }
}
