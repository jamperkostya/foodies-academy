<?php

namespace Tests\Feature;

use Tests\SiteTestCase;

class LikesAndRatingsTest extends SiteTestCase
{
    public function test_like_switch_and_take_back(): void
    {
        $id = $this->recipe('borscht', [], ['pld_like_count' => '5']);

        $this->postJson('/api/recipes/borscht/likes', ['from' => null, 'to' => 'like'])->assertJsonPath('data', ['like' => 6, 'dislike' => 0]);
        $this->postJson('/api/recipes/borscht/likes', ['from' => 'like', 'to' => 'dislike'])->assertJsonPath('data', ['like' => 5, 'dislike' => 1]);
        $this->postJson('/api/recipes/borscht/likes', ['from' => 'dislike', 'to' => null])->assertJsonPath('data', ['like' => 5, 'dislike' => 0]);

        // Stored where the Posts Like Dislike plugin keeps them.
        $this->assertSame('5', $this->postMeta($id, 'pld_like_count'));
        $this->assertSame('0', $this->postMeta($id, 'pld_dislike_count'));
        $this->getJson('/api/recipes/borscht/likes')->assertJsonPath('data', ['like' => 5, 'dislike' => 0]);
    }

    public function test_counts_never_go_below_zero_and_same_vote_is_refused(): void
    {
        $this->recipe('borscht');

        $this->postJson('/api/recipes/borscht/likes', ['from' => 'like', 'to' => null])->assertJsonPath('data.like', 0);
        $this->postJson('/api/recipes/borscht/likes', ['from' => 'like', 'to' => 'like'])->assertUnprocessable();
        $this->postJson('/api/recipes/borscht/likes', ['to' => 'love'])->assertUnprocessable();
        $this->postJson('/api/recipes/nope/likes', ['to' => 'like'])->assertNotFound();
    }

    public function test_video_likes(): void
    {
        $this->makePost('video', 'tom-yam');

        $this->postJson('/api/videos/tom-yam/likes', ['to' => 'like'])->assertJsonPath('data.like', 1);
    }

    public function test_author_star_rating(): void
    {
        $author = $this->user('mariana', 'Мариана');
        $this->recipe('borscht', ['post_author' => $author]);

        $this->postJson('/api/authors/mariana/rating', ['from' => null, 'to' => 5])->assertJsonPath('data', ['average' => 5, 'count' => 1]);
        $this->postJson('/api/authors/mariana/rating', ['from' => null, 'to' => 2])->assertJsonPath('data', ['average' => 3.5, 'count' => 2]);
        $this->postJson('/api/authors/mariana/rating', ['from' => 2, 'to' => 4])->assertJsonPath('data', ['average' => 4.5, 'count' => 2]);
        $this->postJson('/api/authors/mariana/rating', ['from' => 4, 'to' => null])->assertJsonPath('data', ['average' => 5, 'count' => 1]);
        $this->postJson('/api/authors/mariana/rating', ['to' => 6])->assertUnprocessable();
        $this->postJson('/api/authors/nobody/rating', ['to' => 5])->assertNotFound();

        $this->getJson('/api/authors/mariana')->assertJsonPath('data.rating', 5)->assertJsonPath('data.ratingCount', 1);
    }

    public function test_each_action_has_its_own_rate_limit(): void
    {
        $this->recipe('borscht');
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/recipes/borscht/likes', ['to' => 'like']);
        }

        $this->postJson('/api/recipes/borscht/likes', ['to' => 'like'])->assertTooManyRequests();
        // Liking a lot doesn't block a review.
        $this->postJson('/api/recipes/borscht/comments', ['name' => 'Анна', 'text' => 'Вкусно!'])->assertCreated();
    }
}
