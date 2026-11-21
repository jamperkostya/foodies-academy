<?php

namespace Tests\Feature;

use App\Services\Recipes;
use Tests\SiteTestCase;

class EnglishApiTest extends SiteTestCase
{
    private int $ru;

    private int $en;

    protected function setUp(): void
    {
        parent::setUp();
        $ingredients = fn ($name, $unit) => serialize([['amount' => '1,5', 'unit' => $unit, 'ingredient' => $name, 'notes' => '', 'group' => '', 'ingredient_id' => 1, 'amount_normalized' => 1.5]]);
        $this->ru = $this->recipe('borshch', ['post_title' => 'Борщ'], [
            'recipe_ingredients' => $ingredients('Свёкла', 'стакана'),
            'recipe_instructions' => serialize([['description' => 'Сварить.', 'group' => '', 'image' => '']]),
            'recipe_cook_time' => '90', 'recipe_cook_time_text' => 'минут', 'complexity' => 'Легко',
            'recipe_video_embed' => 'https://youtu.be/H89sF9Kdw_4', 'pld_like_count' => '7',
        ]);
        $this->en = $this->recipe('ukrainian-borscht', ['post_title' => 'Ukrainian Borscht'], [
            'recipe_ingredients' => $ingredients('Beetroot', 'стакана'),
            'recipe_instructions' => serialize([['description' => 'Boil.', 'group' => '', 'image' => '']]),
        ]);
        $this->translate($this->ru, $this->en);
        $this->recipe('plov', ['post_title' => 'Плов']);
        $soups = $this->term('course', 'supy', 'Супы', [$this->ru]);
        $this->translateTerm($soups, $this->term('course', 'soups', 'Soups'));
        $this->comment($this->ru, 'Иван', [], 5);
    }

    public function test_the_russian_site_doesnt_see_translations(): void
    {
        $this->getJson('/api/recipes/slugs')->assertExactJson(['data' => ['borshch', 'plov']]);
        $this->getJson('/api/recipes/ukrainian-borscht')->assertNotFound();
        $this->getJson('/api/categories?type=course')->assertJsonPath('data.0.name', 'Супы')->assertJsonCount(1, 'data');
        $this->getJson('/api/recipes/borshch')->assertJsonPath('data.translations', ['ru' => 'borshch', 'en' => 'ukrainian-borscht']);
    }

    public function test_english_lists_have_the_translated_recipes_with_their_texts(): void
    {
        $this->getJson('/api/recipes/slugs?lang=en')->assertExactJson(['data' => ['ukrainian-borscht']]);
        $card = $this->getJson('/api/recipes/new?lang=en')->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame($this->ru, $card['id']);
        $this->assertSame(['ukrainian-borscht', 'Ukrainian Borscht', 'Soups', 0, 7], [$card['slug'], $card['title'], $card['categories']['course'][array_key_first($card['categories']['course'])], $card['ratingCount'], $card['likes']['count']]);
        $this->assertSame('1 h 30 min', $card['time']);
    }

    public function test_an_english_recipe_is_the_original_with_the_translated_texts(): void
    {
        $recipe = $this->getJson('/api/recipes/ukrainian-borscht?lang=en')->assertOk()->json('data');

        $this->assertSame('Ukrainian Borscht', $recipe['title']);
        $this->assertSame([['name' => 'Beetroot', 'amount' => '1.5', 'unit' => 'cups', 'notes' => '', 'group' => '', 'quantity' => 1.5, 'unitCode' => 'glass', 'termId' => 1]], $recipe['ingredients']);
        $this->assertSame([['text' => 'Boil.', 'group' => '']], $recipe['steps']);
        $this->assertSame([['name' => 'Soups', 'slug' => 'soups']], $recipe['categoryLinks']['course']);
        $this->assertSame('H89sF9Kdw_4', $recipe['youtubeId']);
        $this->assertSame('Easy', $recipe['complexity']);
        // Reviews are the translation's own.
        $this->assertSame([], $recipe['reviews']);
        $this->getJson('/api/recipes/borshch?lang=en')->assertNotFound();
    }

    public function test_english_terms_and_strings(): void
    {
        $this->getJson('/api/categories?type=course&lang=en')->assertJsonPath('data.0.name', 'Soups')->assertJsonPath('data.0.count', 1);
        $this->getJson('/api/categories/soups?lang=en')->assertJsonPath('data.recipes.0.slug', 'ukrainian-borscht')->assertJsonPath('data.translations', ['ru' => 'supy', 'en' => 'soups']);
        $this->getJson('/api/categories/supy?lang=en')->assertNotFound();
        // Translated, but no recipe is: no English page to link to.
        $pizza = $this->term('course', 'pizza', 'Пицца', [$this->recipe('margarita')]);
        $this->translateTerm($pizza, $this->term('course', 'pizza-2', 'Pizza'));
        $this->getJson('/api/categories/pizza')->assertJsonPath('data.translations', ['ru' => 'pizza']);
        // Nothing else is translated yet.
        $this->getJson('/api/news?lang=en')->assertExactJson(['data' => []]);

        $en = $this->site()->table('terms')->where('slug', 'en')->value('term_id');
        $this->site()->table('termmeta')->insert(['term_id' => $en, 'meta_key' => '_pll_strings_translations', 'meta_value' => serialize([['Ингредиенты', 'Ingredients'], ['Шаги', '']])]);
        $this->getJson('/api/strings?lang=en')->assertExactJson(['data' => ['Ингредиенты' => 'Ingredients']]);
        $this->getJson('/api/recipes/slugs?lang=de')->assertNotFound();
    }

    public function test_likes_and_favourites_of_an_english_page_are_the_originals(): void
    {
        $this->meta($this->en, 'pld_like_count', '7');
        $this->postJson('/api/recipes/ukrainian-borscht/likes?lang=en', ['from' => null, 'to' => 'like'])->assertJsonPath('data.like', 8);
        $this->postJson('/api/recipes/ukrainian-borscht/comments?lang=en', ['name' => 'John', 'text' => 'Great soup'])->assertCreated();
        $this->assertSame($this->en, (int) $this->site()->table('comments')->where('comment_author', 'John')->value('comment_post_ID'));
        $this->assertSame('8', $this->postMeta($this->ru, 'pld_like_count'));
        $this->assertSame('8', $this->postMeta($this->en, 'pld_like_count'));
        $this->assertSame($this->ru, app(Recipes::class)->getOriginalId('ukrainian-borscht'));
    }

    public function test_old_slugs_of_english_recipes_redirect_under_en(): void
    {
        $this->meta($this->en, '_wp_old_slug', 'borscht-old');

        $this->getJson('/api/redirects')->assertJsonFragment(['from' => 'en/recipe/borscht-old', 'to' => '/en/recipe/ukrainian-borscht']);
    }

    public function test_ingredients_are_named_as_their_terms_and_one_english_name_is_one_page(): void
    {
        $beet = $this->term('ingredient', 'svekla', 'Свёкла', [$this->ru]);
        $this->translateTerm($beet, $this->term('ingredient', 'beetroot', 'Beetroot'));
        $twin = $this->term('ingredient', 'svekla-krasnaya', 'Свекла красная', [$this->ru]);
        $this->translateTerm($twin, $this->term('ingredient', 'beetroot-2', 'Beetroot'));
        $items = unserialize($this->postMeta($this->ru, 'recipe_ingredients'));
        $items[0]['ingredient_id'] = $beet;
        $this->site()->table('postmeta')->where('post_id', $this->ru)->where('meta_key', 'recipe_ingredients')->update(['meta_value' => serialize($items)]);
        $enItems = unserialize($this->postMeta($this->en, 'recipe_ingredients'));
        $enItems[0]['ingredient'] = 'Beets, red';
        $this->site()->table('postmeta')->where('post_id', $this->en)->where('meta_key', 'recipe_ingredients')->update(['meta_value' => serialize($enItems)]);

        // The translator's "Beets, red" gives way to the term's name.
        $this->getJson('/api/recipes/ukrainian-borscht?lang=en')->assertJsonPath('data.ingredients.0.name', 'Beetroot');
        $this->getJson('/api/ingredients?lang=en')->assertExactJson(['data' => [['name' => 'Beetroot', 'slug' => 'beetroot', 'count' => 1, 'indexed' => false]]]);
        $this->getJson('/api/ingredients/beetroot?lang=en')->assertJsonPath('data.total', 1);
        $this->getJson('/api/ingredients/beetroot-2?lang=en')->assertNotFound();
        // The English page names one Russian page; only that one names it back (hreflang goes both ways).
        $this->getJson('/api/ingredients/beetroot?lang=en')->assertJsonPath('data.translations', ['ru' => 'svekla', 'en' => 'beetroot']);
        $this->getJson('/api/ingredients/svekla')->assertJsonPath('data.translations', ['ru' => 'svekla', 'en' => 'beetroot']);
        $this->getJson('/api/ingredients/svekla-krasnaya')->assertJsonPath('data.translations', ['ru' => 'svekla-krasnaya']);
    }

    public function test_site_pages_in_english_are_the_translated_ones_under_the_russian_slug(): void
    {
        $privacy = $this->makePost('page', 'privacy-policy', ['post_title' => 'Политика конфиденциальности', 'post_content' => '<p>Текст</p>'], ['foodies_site_page' => '1']);
        $this->makePost('page', 'terms', ['post_title' => 'Правила'], ['foodies_site_page' => '1']);
        // Polylang gives the translation "-2": the same slug is taken by the Russian page.
        $english = $this->makePost('page', 'privacy-policy-2', ['post_title' => 'Privacy Policy', 'post_content' => '<p>Text</p>'], ['foodies_site_page' => '1']);
        $this->translate($privacy, $english);

        $this->getJson('/api/pages')->assertExactJson(['data' => [
            ['slug' => 'privacy-policy', 'title' => 'Политика конфиденциальности'],
            ['slug' => 'terms', 'title' => 'Правила'],
        ]]);
        $this->getJson('/api/pages?lang=en')->assertExactJson(['data' => [['slug' => 'privacy-policy', 'title' => 'Privacy Policy']]]);
        $this->getJson('/api/pages/privacy-policy?lang=en')
            ->assertJsonPath('data.title', 'Privacy Policy')
            ->assertJsonPath('data.content', '<p>Text</p>')
            ->assertJsonPath('data.translations', ['ru' => 'privacy-policy', 'en' => 'privacy-policy']);
        $this->getJson('/api/pages/privacy-policy')->assertJsonPath('data.content', '<p>Текст</p>');
        $this->getJson('/api/pages/terms?lang=en')->assertNotFound();
    }

    public function test_validation_messages_are_in_the_language_of_the_request(): void
    {
        $data = ['name' => '', 'email' => 'bad', 'password' => '12345678'];

        $this->postJson('/api/auth/register', $data)->assertUnprocessable()->assertJsonPath('message', 'Заполните поле «Имя». (и ещё 1 ошибка)');
        $this->postJson('/api/auth/register?lang=en', $data)->assertUnprocessable()->assertJsonPath('message', 'The name field is required. (and 1 more error)');
    }
}
