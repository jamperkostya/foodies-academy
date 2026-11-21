<?php

namespace App\Services;

use App\Support\Lang;
use App\Support\WpHtml;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/** WordPress pages (post_type "page"), for what the Astro pages take from them, such as the SEO text. */
class Pages
{
    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    /**
     * Pages shown on the site at /{slug} ("Отдельная страница сайта" ticked): [{slug, title}]. In
     * another language: those translated into it (a Polylang translation of the Russian page), with
     * the translation's slug and title.
     */
    public function getSitePages(): array
    {
        $pages = $this->db->table('posts as p')
            ->join('postmeta as m', function ($join) {
                $join->on('m.post_id', '=', 'p.ID')->where('m.meta_key', 'foodies_site_page')->where('m.meta_value', '1');
            })
            ->where('p.post_type', 'page')
            ->where('p.post_status', 'publish')->tap(fn ($q) => app(Translations::class)->scope($q))
            ->orderBy('p.menu_order')
            ->orderBy('p.ID')
            ->get(['p.ID', 'p.post_name', 'p.post_title']);
        $translated = app(Translations::class)->posts($pages->pluck('ID')->all());

        return $pages
            ->map(fn ($page) => $translated[$page->ID] ?? $page)
            ->map(fn ($page) => ['slug' => $page->post_name, 'title' => html_entity_decode($page->post_title, ENT_QUOTES | ENT_HTML5)])
            ->values()
            ->all();
    }

    /**
     * {id, slug, title, excerpt, content (HTML), image, seoText} of a published page, or null.
     * "home" is the WordPress front page.
     */
    public function getBySlug(string $slug): ?array
    {
        if (! Lang::isDefault()) {
            $found = app(Translations::class)->find('page', $slug);
            $page = $found ? $this->db->table('posts')->where('ID', $found['id'])->first() : null;

            return $page ? $this->present($page, $slug) : null;
        }
        $query = $this->db->table('posts')->where('post_type', 'page')->where('post_status', 'publish')->tap(fn ($q) => app(Translations::class)->scope($q, 'posts.ID'));
        $page = $slug === 'home'
            ? $query->where('ID', (int) $this->db->table('options')->where('option_name', 'page_on_front')->value('option_value'))->first()
            : $query->where('post_name', $slug)->first();
        if (! $page) {
            return null;
        }

        return $this->present($page, $page->post_name);
    }

    /** A page under its address on the site ($slug: a translation's public slug). */
    private function present(object $page, string $slug): array
    {
        $thumbnail = (int) $this->db->table('postmeta')->where('post_id', $page->ID)->where('meta_key', '_thumbnail_id')->value('meta_value');

        return [
            'id' => (int) $page->ID,
            'slug' => $slug,
            'title' => html_entity_decode($page->post_title, ENT_QUOTES | ENT_HTML5),
            'excerpt' => trim(html_entity_decode(strip_tags($page->post_excerpt), ENT_QUOTES | ENT_HTML5)),
            'content' => WpHtml::render($page->post_content),
            'image' => $thumbnail ? (app(Attachments::class)->urls([$thumbnail])[$thumbnail] ?? '') : '',
            'modified' => $page->post_modified_gmt,
            'seoText' => app(SeoText::class)->forPost((int) $page->ID),
            'seo' => app(Seo::class)->forPageId((int) $page->ID),
            // Its address in every language it is published in: [ru => slug, en => slug].
            'translations' => app(Translations::class)->slugs((int) $page->ID),
        ];
    }
}
