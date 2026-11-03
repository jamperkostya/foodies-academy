<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Recipe collections ("С курицей", "С мясом"…): recipes with any of the listed ingredients.
 * Ingredients are listed by exact name, because a name search mixes things up:
 * "кур" also finds "Яйцо куриное", "паст" finds "Томатная паста".
 */
class Collections
{
    private const COLLECTIONS = [
        's-kuricey' => [
            'title' => 'С курицей',
            'ingredients' => ['Курица', 'Куриные бёдра', 'Куриное филе', 'Цыплёнок', 'Куриный фарш'],
        ],
        's-myasom' => [
            'title' => 'С мясом',
            'ingredients' => [
                'Говядина', 'Говяжий фарш', 'Говяжья голень', 'Говяжья вырезка',
                'Свинина', 'Свинина постная', 'Свиной фарш', 'Свиная голяшка', 'Свиная вырезка', 'Свиной подчерёвок (грудинка)',
                'Телятина', 'Рубленая телятина', 'Телячья мякоть',
                'Баранина', 'Бараний фарш', 'Баранья лопатка', 'Баранья нога', 'Мясо ягненка',
                'Мясной фарш', 'Фарш',
            ],
        ],
        's-ryboy' => [
            'title' => 'С рыбой',
            'ingredients' => [
                'Морская рыба', 'Рыба свежая', 'Рыба белая', 'Рыба морской черт', 'Речная рыба',
                'Лосось свежий', 'Стейк лосося', 'Филе лосося', 'Филе лосося со шкурой',
                'Треска', 'Треска филе', 'Тунец консервированный', 'Филе семги', 'Форель',
            ],
        ],
        's-pastoy' => [
            'title' => 'С пастой',
            'ingredients' => ['Спагетти', 'Черные спагетти', 'Макароны', 'Феттучини', 'Пенне'],
        ],
        // Not "Яичная лапша" or "Бычьи яйца".
        's-yaytsami' => [
            'title' => 'С яйцами',
            'ingredients' => [
                'Яйцо куриное', 'Яйцо куриное (вареное)', 'Яйцо', 'Яйцо перепелиное', 'Яйцо пашот',
                'Желток яичный', 'Белок яичный', 'Маринованные яйцо (ajitsuke tamago)',
            ],
        ],
    ];

    private ConnectionInterface $db;

    public function __construct(private Recipes $recipes)
    {
        $this->db = DB::connection('site');
    }

    /** Every collection with its recipe count and the cover of its most viewed recipe. */
    public function getAll(): array
    {
        return collect(self::COLLECTIONS)->map(function ($collection, $slug) {
            $top = $this->recipes->present($this->query($slug)->limit(1)->get(['p.*']));

            return [
                'slug' => $slug,
                'title' => $collection['title'],
                'count' => $this->query($slug)->distinct()->count('p.ID'),
                'image' => $top[0]['image'] ?? '',
            ];
        })->values()->all();
    }

    /** One collection with one page of its recipes, most viewed first, or null. */
    public function getBySlug(string $slug, int $page = 1, int $perPage = 24): ?array
    {
        if (! isset(self::COLLECTIONS[$slug])) {
            return null;
        }

        return [
            'slug' => $slug,
            'title' => self::COLLECTIONS[$slug]['title'],
        ] + $this->recipes->paginate($this->query($slug), $page, $perPage);
    }

    /** Published Russian recipes with any of the collection's ingredients, most viewed first. */
    private function query(string $slug): Builder
    {
        $termIds = $this->db->table('term_taxonomy as tt')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'ingredient')
            ->whereIn('t.name', self::COLLECTIONS[$slug]['ingredients'])
            ->pluck('tt.term_taxonomy_id');

        return $this->db->table('posts as p')
            ->leftJoin('post_views_realtime as v', function ($join) {
                $join->on('v.post_id', '=', 'p.ID')
                    ->where('v.view_type', 'normal')
                    ->where('v.output_type', 'content');
            })
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            // whereExists, not a join: a recipe with two of the ingredients shows up once
            ->whereExists(fn ($q) => $q->from('term_relationships as r')
                ->whereColumn('r.object_id', 'p.ID')
                ->whereIn('r.term_taxonomy_id', $termIds))
            ->orderByDesc('v.post_views_total');
    }
}
