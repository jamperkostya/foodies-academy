<?php

namespace Tests\Feature;

use App\Services\Translations;
use Tests\SiteTestCase;

class TranslationsTest extends SiteTestCase
{
    private function likes(int $id): array
    {
        $likes = array_map('intval', $this->site()->table('postmeta')->where('post_id', $id)->whereIn('meta_key', ['pld_like_count', 'pld_dislike_count'])->pluck('meta_value', 'meta_key')->all());
        ksort($likes);

        return $likes;
    }

    private function views(int $id): int
    {
        return (int) $this->site()->table('post_views_realtime')->where('post_id', $id)->value('post_views_total');
    }

    public function test_a_post_and_its_translations(): void
    {
        $ru = $this->recipe('borscht');
        $en = $this->recipe('borscht-en', ['post_status' => 'draft']);
        $alone = $this->recipe('plov');
        $this->translate($ru, $en);

        $translations = app(Translations::class);
        $this->assertSame(['ru' => $ru, 'en' => $en], $translations->group($en));
        $this->assertSame(['ru' => $alone], $translations->group($alone));
        $this->assertSame([$en], $translations->translatedIds());
    }

    public function test_likes_and_views_are_shared_by_the_translations(): void
    {
        $ru = $this->recipe('borscht', [], ['pld_like_count' => '5']);
        $en = $this->recipe('ukrainian-borscht', [], ['pld_like_count' => '5']);
        $this->translate($ru, $en);

        $this->postJson('/api/recipes/borscht/likes', ['from' => null, 'to' => 'like'])->assertJsonPath('data.like', 6);
        $this->postJson('/api/recipes/ukrainian-borscht/likes', ['from' => null, 'to' => 'dislike'])->assertJsonPath('data', ['like' => 6, 'dislike' => 1]);
        $this->assertSame(['pld_dislike_count' => 1, 'pld_like_count' => 6], $this->likes($ru));
        $this->assertSame(['pld_dislike_count' => 1, 'pld_like_count' => 6], $this->likes($en));

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone)')->postJson('/api/recipes/borscht/view')->assertNoContent();
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone)')->postJson('/api/recipes/ukrainian-borscht/view')->assertNoContent();
        $this->assertSame(2, $this->views($ru));
        $this->assertSame(2, $this->views($en));
        $this->assertSame(2, (int) $this->site()->table('post_views')->where('id', $en)->where('type', 4)->value('count'));
    }

    public function test_a_new_translation_gets_the_counts_of_its_russian_post(): void
    {
        $ru = $this->recipe('borscht', [], ['pld_like_count' => '7', 'pld_dislike_count' => '2']);
        $en = $this->recipe('ukrainian-borscht', ['post_status' => 'draft'], ['pld_like_count' => '1']);
        $this->translate($ru, $en);
        $this->site()->table('post_views')->insert([['id' => $ru, 'type' => 4, 'period' => 'total', 'count' => 40], ['id' => $en, 'type' => 4, 'period' => 'total', 'count' => 3]]);
        $this->site()->table('post_views_realtime')->insert([
            'post_id' => $ru, 'view_type' => 'normal', 'output_type' => 'content', 'post_views_today' => 0, 'post_views_week' => 0,
            'post_views_month' => 0, 'post_views_halfyear' => 0, 'post_views_year' => 0, 'post_views_total' => 40, 'latest_view_time' => '2026-01-01 00:00:00',
        ]);

        $this->artisan('translations:sync-counters')->assertSuccessful();
        $this->artisan('translations:sync-counters')->assertSuccessful();

        $this->assertSame(['pld_dislike_count' => 2, 'pld_like_count' => 7], $this->likes($en));
        $this->assertSame([40], $this->site()->table('post_views')->where('id', $en)->pluck('count')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(40, $this->views($en));
        // The Russian post keeps its own.
        $this->assertSame(['pld_dislike_count' => 2, 'pld_like_count' => 7], $this->likes($ru));
    }

    public function test_typos_and_mixed_up_fields_of_ingredients_are_fixed_in_russian_recipes_only(): void
    {
        $item = fn ($amount, $unit, $name, $group = '') => ['amount' => $amount, 'unit' => $unit, 'ingredient' => $name, 'notes' => '', 'group' => $group, 'ingredient_id' => 1, 'amount_normalized' => 0];
        $ru = $this->recipe('a', [], ['recipe_ingredients' => serialize([
            $item('1?2', 'ч.л.', 'Соль'),
            $item('1', 'шепотка ', 'Сахар'),
            $item('3', 'CV', 'Имбирь'),
            $item('', '1 пучок', 'Укроп'),
            $item('', 'пару веточек', 'Кинза'),
            $item('100 мл', '', 'Лимонный сок'),
            $item('по вкусу', '', 'Перец'),
            $item('3', 'дольки', 'Чеснок'),
            $item('1', 'долька', 'Лимон'),
            $item('1', 'шт', 'Морской угорь', 'ОБЯЗАТЕЛЬНАЯ РЫБА (МИНИМУМ 4 ИХ НИЖЕ ПЕРЕЧИСЛЕННЫХ)'),
        ])]);
        $en = $this->recipe('a-en', [], ['recipe_ingredients' => serialize([$item('1', 'шепотка', 'Sugar')])]);
        $this->translate($ru, $en);

        $this->artisan('recipes:fix-ingredients')->assertSuccessful();
        $this->artisan('recipes:fix-ingredients --dry-run')->expectsOutputToContain('Would fix 0 ingredients.')->assertSuccessful();

        $fixed = unserialize($this->postMeta($ru, 'recipe_ingredients'));
        $this->assertSame([
            ['1/2', 'ч.л.', 0.5], ['1', 'щепотка', 1.0], ['3', 'см', 3.0], ['1', 'пучок', 1.0], ['пару', 'веточек', 0.0],
            ['100', 'мл', 100.0], ['', 'по вкусу', 0.0], ['3', 'зубчика', 3.0], ['1', 'долька', 0],
            ['1', 'шт', 0],
        ], array_map(fn ($i) => [$i['amount'], $i['unit'], $i['amount_normalized']], $fixed));
        $this->assertSame('ОБЯЗАТЕЛЬНАЯ РЫБА (МИНИМУМ 4 ИЗ НИЖЕ ПЕРЕЧИСЛЕННЫХ)', $fixed[9]['group']);
        $this->assertSame('шепотка', unserialize($this->postMeta($en, 'recipe_ingredients'))[0]['unit']);
    }
}
