<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use App\Support\WpHtml;
use Illuminate\Support\Facades\DB;

/** WordPress pages (post_type "page"), for what the Astro pages take from them, such as the SEO text. */
class Pages
{
    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    /** Pages shown on the site at /{slug} ("Отдельная страница сайта" ticked): [{slug, title}]. */
    public function getSitePages(): array
    {
        return $this->db->table('posts as p')
            ->join('postmeta as m', function ($join) {
                $join->on('m.post_id', '=', 'p.ID')->where('m.meta_key', 'foodies_site_page')->where('m.meta_value', '1');
            })
            ->where('p.post_type', 'page')
            ->where('p.post_status', 'publish')
            ->orderBy('p.menu_order')
            ->orderBy('p.ID')
            ->get(['p.post_name', 'p.post_title'])
            ->map(fn ($page) => ['slug' => $page->post_name, 'title' => html_entity_decode($page->post_title, ENT_QUOTES | ENT_HTML5)])
            ->all();
    }

    /**
     * {id, slug, title, excerpt, content (HTML), image, seoText} of a published page, or null.
     * "home" is the WordPress front page.
     */
    public function getBySlug(string $slug): ?array
    {
        $query = $this->db->table('posts')->where('post_type', 'page')->where('post_status', 'publish');
        $page = $slug === 'home'
            ? $query->where('ID', (int) $this->db->table('options')->where('option_name', 'page_on_front')->value('option_value'))->first()
            : $query->where('post_name', $slug)->first();
        if (! $page) {
            return null;
        }

        $thumbnail = (int) $this->db->table('postmeta')->where('post_id', $page->ID)->where('meta_key', '_thumbnail_id')->value('meta_value');

        return [
            'id' => (int) $page->ID,
            'slug' => $page->post_name,
            'title' => html_entity_decode($page->post_title, ENT_QUOTES | ENT_HTML5),
            'excerpt' => trim(html_entity_decode(strip_tags($page->post_excerpt), ENT_QUOTES | ENT_HTML5)),
            'content' => WpHtml::render($page->post_content),
            'image' => $thumbnail ? (app(Attachments::class)->urls([$thumbnail])[$thumbnail] ?? '') : '',
            'modified' => $page->post_modified_gmt,
            'seoText' => app(SeoText::class)->forPost((int) $page->ID),
            'seo' => app(Seo::class)->forPageId((int) $page->ID),
        ];
    }
}
