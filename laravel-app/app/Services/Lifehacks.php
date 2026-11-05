<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lifehacks: WordPress posts of type "lifehack" (wp-content/mu-plugins/foodies-lifehacks.php)
 * as Astro Lifehack objects.
 */
class Lifehacks
{
    private const META = [
        'lifehack_icon', 'lifehack_title_accent', 'lifehack_intro', 'lifehack_points', 'lifehack_tip_title',
        'lifehack_tip_text', 'lifehack_video_minutes', 'lifehack_image_url', '_thumbnail_id',
    ];

    private ConnectionInterface $db;

    public function __construct()
    {
        $this->db = DB::connection('site');
    }

    /** Every published lifehack, newest first, with all fields except comments. */
    public function getAll(): array
    {
        return $this->present($this->published()->orderByDesc('post_date')->get());
    }

    public function getId(string $slug): ?int
    {
        $id = $this->published()->where('post_name', $slug)->value('ID');

        return $id ? (int) $id : null;
    }

    /** One lifehack with its approved comments, SEO text and Yoast fields, or null. */
    public function getBySlug(string $slug): ?array
    {
        $post = $this->published()->where('post_name', $slug)->first();
        if (! $post) {
            return null;
        }

        return $this->present(collect([$post]))[0] + [
            'comments' => app(Comments::class)->getForPost($post->ID),
            'seoText' => app(SeoText::class)->forPost($post->ID),
            'seo' => app(Seo::class)->forPost('lifehack', $post->post_name),
        ];
    }

    private function published()
    {
        return $this->db->table('posts')->where('post_type', 'lifehack')->where('post_status', 'publish');
    }

    private function present(Collection $posts): array
    {
        if ($posts->isEmpty()) {
            return [];
        }
        $ids = $posts->pluck('ID')->all();
        $meta = $this->db->table('postmeta')->whereIn('post_id', $ids)->whereIn('meta_key', self::META)
            ->get(['post_id', 'meta_key', 'meta_value'])->groupBy('post_id')->map(fn ($rows) => $rows->pluck('meta_value', 'meta_key'));
        $categories = $this->db->table('term_relationships as r')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'r.term_taxonomy_id')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->whereIn('r.object_id', $ids)->where('tt.taxonomy', 'lifehack_category')
            ->get(['r.object_id', 't.name'])->groupBy('object_id')->map(fn ($rows) => html_entity_decode($rows->first()->name, ENT_QUOTES | ENT_HTML5));
        $images = app(Attachments::class)->urls($meta->map(fn ($m) => (int) $m->get('_thumbnail_id', 0))->filter()->values()->all());
        $users = $this->db->table('users')->whereIn('ID', $posts->pluck('post_author'))->get(['ID', 'user_nicename', 'display_name'])->keyBy('ID');
        $recipeCounts = collect(app(Authors::class)->getAll())->pluck('recipes', 'id');

        return $posts->map(function ($post) use ($meta, $categories, $images, $users, $recipeCounts) {
            $m = $meta->get($post->ID, collect());
            $user = $users->get($post->post_author);
            $points = $this->points((string) $m->get('lifehack_points', ''));
            $intro = trim((string) $m->get('lifehack_intro', ''));
            $tip = ['title' => trim((string) $m->get('lifehack_tip_title', '')), 'text' => trim((string) $m->get('lifehack_tip_text', ''))];
            $recipes = (int) ($recipeCounts[(int) $post->post_author] ?? 0);

            return [
                'id' => (int) $post->ID,
                'slug' => $post->post_name,
                'title' => html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5),
                'titleAccent' => trim((string) $m->get('lifehack_title_accent', '')),
                'category' => $categories->get($post->ID, ''),
                'icon' => (string) $m->get('lifehack_icon', '💡'),
                // The featured image wins over the picture the placeholder lifehacks came with.
                'image' => $images[(int) $m->get('_thumbnail_id', 0)] ?? (string) $m->get('lifehack_image_url', ''),
                'description' => trim(html_entity_decode(strip_tags($post->post_excerpt), ENT_QUOTES | ENT_HTML5)),
                'intro' => $intro,
                'points' => $points,
                'tip' => $tip,
                'author' => [
                    'slug' => $user->user_nicename ?? '',
                    'name' => $user ? html_entity_decode($user->display_name, ENT_QUOTES | ENT_HTML5) : 'Foodies Academy',
                    'info' => $recipes ? $recipes.' '.$this->recipesWord($recipes) : '',
                ],
                'date' => Carbon::parse($post->post_date)->locale('ru')->translatedFormat('j F Y'),
                'readTime' => $this->readTime([$post->post_excerpt, $intro, $tip['text'], ...array_merge(...array_map('array_values', $points))]),
                'videoMinutes' => (int) $m->get('lifehack_video_minutes', 0) ?: null,
                'commentCount' => (int) $post->comment_count,
            ];
        })->values()->all();
    }

    /** "Мало воды. | На 100 г пасты…" per line → [{title, text}]; a line without "|" is all text. */
    private function points(string $value): array
    {
        return collect(preg_split('/\R/u', $value))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->map(function ($line) {
                [$title, $text] = str_contains($line, '|') ? array_map('trim', explode('|', $line, 2)) : ['', $line];

                return ['title' => $title, 'text' => $text];
            })
            ->values()
            ->all();
    }

    // About 180 words a minute, at least one.
    private function readTime(array $texts): int
    {
        $words = str_word_count(strip_tags(implode(' ', $texts)), 0, 'АаБбВвГгДдЕеЁёЖжЗзИиЙйКкЛлМмНнОоПпРрСсТтУуФфХхЦцЧчШшЩщЪъЫыЬьЭэЮюЯя');

        return max(1, (int) round($words / 180));
    }

    private function recipesWord(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        return match (true) {
            $mod10 === 1 && $mod100 !== 11 => 'рецепт',
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => 'рецепта',
            default => 'рецептов',
        };
    }
}
