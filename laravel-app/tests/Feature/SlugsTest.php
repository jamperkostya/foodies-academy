<?php

namespace Tests\Feature;

use Tests\SiteTestCase;

class SlugsTest extends SiteTestCase
{
    public function test_clean_slugs_pass(): void
    {
        $this->recipe('borshh-ukrainskij');
        $this->recipe('fua_gra-terrine');
        $this->term('course', 'soups', 'Супы');

        $this->artisan('slugs:check')->expectsOutput('All slugs are fine.')->assertSuccessful();
    }

    public function test_bad_slugs_are_reported(): void
    {
        $this->recipe('%d0%b1%d0%be%d1%80%d1%89'); // "борщ", percent-encoded
        $this->recipe('Pasta');
        $this->makePost('post', 'mango');
        $this->makePost('post', 'mango');
        $this->term('course', 'snacks', 'Закуски');
        $this->term('cuisine', 'snacks', 'Снэк-страна');
        $this->makePost('page', 'blog', [], ['foodies_site_page' => '1']);

        $this->artisan('slugs:check')
            ->expectsOutputToContain('invalid slug "борщ"')
            ->expectsOutputToContain('invalid slug "Pasta"')
            ->expectsOutputToContain('post: slug "mango" is used 2 times')
            ->expectsOutputToContain('/category/snacks is both a dish type and a country')
            ->expectsOutputToContain('site page /blog is taken by a section of the site')
            ->assertFailed();
    }

    public function test_api_routes_take_every_valid_slug_and_reject_others(): void
    {
        $this->recipe('a_b-9');

        $this->getJson('/api/recipes/a_b-9')->assertOk();
        $this->getJson('/api/recipes/A_B-9')->assertNotFound();
        $this->getJson('/api/recipes/'.rawurlencode('борщ'))->assertNotFound();
    }
}
