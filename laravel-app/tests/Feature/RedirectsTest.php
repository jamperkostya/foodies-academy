<?php

namespace Tests\Feature;

use Tests\SiteTestCase;

class RedirectsTest extends SiteTestCase
{
    public function test_shortlinks_lead_to_recipes_and_blog_posts(): void
    {
        $recipe = $this->recipe('tartar-iz-lososya');
        $post = $this->makePost('post', 'rizotto-s-grushey');
        $this->makePost('news', 'novost');
        $this->recipe('draft', ['post_status' => 'draft']);

        $this->getJson('/api/redirects')->assertOk()->assertJsonPath('data.ids', [
            ['id' => $recipe, 'to' => '/recipe/tartar-iz-lososya'],
            ['id' => $post, 'to' => '/blog/rizotto-s-grushey'],
        ]);
    }

    public function test_old_recipe_slugs_lead_to_the_current_ones(): void
    {
        $sauce = $this->recipe('sous-chimichurri');
        $this->meta($sauce, '_wp_old_slug', 'sous-chimichurri-chimichurri');
        $soup = $this->recipe('gaspacho');
        $this->meta($soup, '_wp_old_slug', '%d0%b3%d0%b0%d1%81%d0%bf%d0%b0%d1%87%d0%be'); // "гаспачо"
        // WordPress gave the old slug of one recipe to another: the live one keeps its address.
        $this->recipe('tom-yam');
        $goulash = $this->recipe('goulash');
        $this->meta($goulash, '_wp_old_slug', 'tom-yam');
        $this->meta($goulash, '_wp_old_slug', 'bad slug');
        // Renamed twice to the same slug: one rule, to the newest recipe.
        $first = $this->recipe('pasta-1');
        $this->meta($first, '_wp_old_slug', 'pasta');
        $second = $this->recipe('pasta-2');
        $this->meta($second, '_wp_old_slug', 'pasta');

        $slugs = $this->getJson('/api/redirects')->assertOk()->json('data.slugs');

        $this->assertEqualsCanonicalizing([
            ['from' => 'recipe/sous-chimichurri-chimichurri', 'to' => '/recipe/sous-chimichurri'],
            ['from' => 'recipe/гаспачо', 'to' => '/recipe/gaspacho'],
            ['from' => 'recipe/pasta', 'to' => '/recipe/pasta-2'],
        ], $slugs);
    }
}
