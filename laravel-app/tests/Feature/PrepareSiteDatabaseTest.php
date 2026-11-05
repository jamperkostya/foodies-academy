<?php

namespace Tests\Feature;

use Tests\SiteTestCase;

class PrepareSiteDatabaseTest extends SiteTestCase
{
    private function translation(string $type, int $id, string $language, int $trid): void
    {
        $this->site()->table('icl_translations')->insert(['element_type' => $type, 'element_id' => $id, 'trid' => $trid, 'language_code' => $language]);
    }

    private function prepare(): void
    {
        $this->artisan('site:prepare-db', ['--force' => true, '--no-videos' => true])->assertSuccessful();
    }

    public function test_deletes_what_is_not_in_russian_and_keeps_the_rest(): void
    {
        $ru = $this->recipe('borscht');
        $en = $this->recipe('borscht-en', [], ['recipe_servings' => '4']);
        $revision = $this->makePost('revision', 'borscht-en-revision', ['post_parent' => $en]);
        $image = $this->makePost('attachment', 'photo-en');
        $this->comment($en, 'Bob', [], 5);
        $soups = $this->term('course', 'soups', 'Супы', [$ru, $en]);
        $soupsEn = $this->term('course', 'soups-en', 'Soups', [$en]);
        $this->translation('post_recipe', $ru, 'ru', 1);
        $this->translation('post_recipe', $en, 'en', 1);
        $this->translation('post_attachment', $image, 'en', 2);
        $soupsEnTt = (int) $this->site()->table('term_taxonomy')->where('term_id', $soupsEn)->value('term_taxonomy_id');
        $this->translation('tax_course', $soupsEnTt, 'en', 3);

        $this->prepare();

        $site = $this->site();
        $this->assertTrue($site->table('posts')->where('ID', $ru)->exists());
        $this->assertFalse($site->table('posts')->whereIn('ID', [$en, $revision])->exists());
        $this->assertFalse($site->table('postmeta')->where('post_id', $en)->exists());
        $this->assertFalse($site->table('comments')->where('comment_post_ID', $en)->exists());
        // Images stay: Russian posts may use them.
        $this->assertTrue($site->table('posts')->where('ID', $image)->exists());
        $this->assertFalse($site->table('terms')->where('term_id', $soupsEn)->exists());
        $this->assertSame(1, (int) $site->table('term_taxonomy')->where('term_id', $soups)->value('count'));
        $this->assertSame(['ru', 'en'], $site->table('icl_translations')->orderBy('translation_id')->pluck('language_code')->all());
        $this->getJson('/api/recipes/slugs')->assertJsonPath('data', ['borscht']);
    }

    public function test_adds_the_lifehacks_once(): void
    {
        $this->prepare();
        $this->prepare();

        $lifehacks = $this->site()->table('posts')->where('post_type', 'lifehack')->where('post_status', 'publish');
        $this->assertSame(16, $lifehacks->count());
        $this->assertSame(5, $this->site()->table('term_taxonomy')->where('taxonomy', 'lifehack_category')->count());
        $this->assertSame(16, (int) $this->site()->table('term_taxonomy')->where('taxonomy', 'lifehack_category')->sum('count'));
        $this->getJson('/api/lifehacks/onion-without-tears')->assertOk()->assertJsonPath('data.title', 'Как нарезать лук без слёз');
    }

    public function test_old_likes_become_plugin_counters(): void
    {
        $id = $this->recipe('borscht', [], ['like_post' => '12', 'unlike_post' => '2']);

        $this->prepare();

        $this->getJson('/api/recipes/borscht')->assertJsonPath('data.votes', ['like' => 12, 'dislike' => 2]);
        $this->assertSame('1', $this->postMeta($id, 'pld_legacy_imported'));
    }
}
