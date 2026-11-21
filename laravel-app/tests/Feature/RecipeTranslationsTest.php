<?php

namespace Tests\Feature;

use Tests\SiteTestCase;

class RecipeTranslationsTest extends SiteTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/translations-test-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("$this->dir/*"));
        rmdir($this->dir);
        parent::tearDown();
    }

    private function ingredient(string $amount, string $unit, string $name, string $group = '', string $notes = ''): array
    {
        return ['amount' => $amount, 'unit' => $unit, 'ingredient' => $name, 'notes' => $notes, 'group' => $group, 'ingredient_id' => 1, 'amount_normalized' => (float) str_replace(',', '.', $amount)];
    }

    /** A recipe with a photo; returns [recipe id, photo path]. */
    private function recipeWithPhoto(string $slug, string $title, array $ingredients, string $link = 'cover'): array
    {
        $id = $this->recipe($slug, ['post_title' => $title], ['recipe_ingredients' => serialize($ingredients)]);
        $path = "2020/01/$slug.jpg";
        $photo = $this->makePost('attachment', "$slug-photo", ['post_parent' => $link === 'upload' ? $id : 0], ['_wp_attached_file' => $path]);
        match ($link) {
            'cover' => $this->meta($id, '_thumbnail_id', (string) $photo),
            'gallery' => $this->meta($id, 'gallery', serialize([(string) $photo])),
            'upload' => null,
        };

        return [$id, $path];
    }

    private function prepare(array $source): array
    {
        file_put_contents("$this->dir/source.json", json_encode($source));
        $this->artisan('recipes:prepare-translations', ['source' => "$this->dir/source.json", '--output' => "$this->dir/out.json"])->assertSuccessful();

        return json_decode(file_get_contents("$this->dir/out.json"), true);
    }

    public function test_a_translation_is_bound_to_its_recipe_by_the_photo_with_the_recipes_own_amounts(): void
    {
        [$id, $path] = $this->recipeWithPhoto('kuritsa', 'Курица', [
            $this->ingredient('1,5', 'кг', 'Курица'),
            $this->ingredient('2', 'зубчика', 'Чеснок', '', 'мелко нарезанный'),
            $this->ingredient('100', 'гр', 'Мёд', 'ДЛЯ МАРИНАДА'),
        ]);

        $out = $this->prepare([[
            'title' => 'BAKED CHICKEN (КУРИЦА ЗАПЕЧЁННАЯ)',
            'photo_url' => "https://foodies.academy.local/wp-content/uploads/$path",
            'category' => 'Main Dishes (Вторые блюда)',
            'country' => '',
            'ingredients' => [
                'Default' => [['item' => 'Chicken', 'amount' => '1.5 kg'], ['item' => 'Garlic (finely chopped)', 'amount' => '2 cloves']],
                'FOR THE MARINADE' => [['item' => 'Honey', 'amount' => '100 g']],
            ],
            'instructions' => ['Default' => ['Preheat the oven to 200 degrees C.', ' '], 'SERVING' => ['Serve hot.']],
        ]]);

        $recipe = $out['recipes'][0];
        $this->assertSame($id, $recipe['source_id']);
        $this->assertSame('kuritsa', $recipe['source_slug']);
        $this->assertSame('Baked Chicken', $recipe['title']);
        // Names, notes and groups only: amounts and units stay the Russian recipe's.
        $this->assertSame([
            ['name' => 'Chicken', 'notes' => '', 'group' => '', 'unit' => 'kg'],
            ['name' => 'Garlic', 'notes' => 'finely chopped', 'group' => '', 'unit' => 'cloves'],
            ['name' => 'Honey', 'notes' => '', 'group' => 'FOR THE MARINADE', 'unit' => 'g'],
        ], $recipe['ingredients']);
        $this->assertSame([
            ['group' => '', 'text' => 'Preheat the oven to 200°C (400°F).'],
            ['group' => 'SERVING', 'text' => 'Serve hot.'],
        ], $recipe['instructions']);
        $this->assertSame([], $recipe['warnings']);
    }

    public function test_photos_in_the_gallery_or_uploaded_to_the_recipe_find_it_too(): void
    {
        [$gallery, $galleryPath] = $this->recipeWithPhoto('plov', 'Плов', [], 'gallery');
        [$upload, $uploadPath] = $this->recipeWithPhoto('borshch', 'Борщ', [], 'upload');

        $out = $this->prepare([
            ['title' => 'Plov', 'photo_url' => "http://x/wp-content/uploads/$galleryPath", 'ingredients' => [], 'instructions' => []],
            ['title' => 'Borscht', 'photo_url' => "http://x/wp-content/uploads/$uploadPath", 'ingredients' => [], 'instructions' => []],
            ['title' => 'Nothing', 'photo_url' => 'http://x/wp-content/uploads/2020/01/none.jpg', 'ingredients' => [], 'instructions' => []],
        ]);

        $this->assertSame([$gallery, $upload], array_column($out['recipes'], 'source_id'));
    }

    public function test_ingredients_that_dont_line_up_with_the_recipe_are_left_out(): void
    {
        [, $fewer] = $this->recipeWithPhoto('a', 'А', [$this->ingredient('1', 'шт', 'Яйцо'), $this->ingredient('2', 'шт', 'Лук')]);
        [, $shuffled] = $this->recipeWithPhoto('b', 'Б', [$this->ingredient('1', 'шт', 'Яйцо'), $this->ingredient('200', 'гр', 'Мука')]);

        $out = $this->prepare([
            ['title' => 'A', 'photo_url' => "x/wp-content/uploads/$fewer", 'ingredients' => ['Default' => [['item' => 'Egg', 'amount' => '1 pc']]], 'instructions' => []],
            ['title' => 'B', 'photo_url' => "x/wp-content/uploads/$shuffled", 'ingredients' => ['Default' => [['item' => 'Flour', 'amount' => '200 g'], ['item' => 'Egg', 'amount' => '1 pc']]], 'instructions' => []],
        ]);

        $this->assertNull($out['recipes'][0]['ingredients']);
        $this->assertStringContainsString('1 in the translation, 2 in the recipe', $out['recipes'][0]['warnings'][0]);
        $this->assertNull($out['recipes'][1]['ingredients']);
    }

    public function test_a_title_left_as_draft_takes_the_original_name_from_the_russian_title(): void
    {
        [$id, $path] = $this->recipeWithPhoto('mapo', 'МАПО ТОФУ (Mapo Tofu)', []);

        $out = $this->prepare([['title' => 'Draft', 'photo_url' => "x/wp-content/uploads/$path", 'ingredients' => [], 'instructions' => []]]);

        $this->assertSame('Mapo Tofu', $out['recipes'][0]['title']);
    }

    public function test_units_check_lists_units_it_doesnt_know(): void
    {
        $id = $this->recipe('a', [], ['recipe_ingredients' => serialize([
            $this->ingredient('1', 'ст. л.', 'Масло'),
            $this->ingredient('', '1/2', 'Соль'),
        ])]);

        $this->artisan('units:check')
            ->expectsTable(['Unit', 'Times', 'Recipes (ID)'], [['1/2', 1, (string) $id]])
            ->assertSuccessful();
    }
}
