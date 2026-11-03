<?php

namespace Tests\Feature;

use Tests\SiteTestCase;

class ReviewsTest extends SiteTestCase
{
    public function test_a_review_is_saved_for_moderation_with_its_stars(): void
    {
        $id = $this->recipe('borscht');

        $this->postJson('/api/recipes/borscht/comments', ['name' => 'Анна', 'text' => 'Очень вкусно!', 'rating' => 5])
            ->assertCreated();

        $comment = $this->site()->table('comments')->where('comment_post_ID', $id)->first();
        $this->assertSame('0', $comment->comment_approved);
        $this->assertSame('Анна', $comment->comment_author);
        $this->assertSame('5', $this->site()->table('commentmeta')->where('comment_id', $comment->comment_ID)->value('meta_value'));
        // Pending: not on the site yet.
        $this->getJson('/api/recipes/borscht')->assertJsonCount(0, 'data.reviews');
    }

    public function test_review_validation_is_in_russian(): void
    {
        $this->recipe('borscht');

        $this->postJson('/api/recipes/borscht/comments', ['name' => 'А', 'text' => '', 'rating' => 9])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'text', 'rating'])
            ->assertJsonPath('errors.name.0', 'Поле «Имя» должно быть не короче 2 символов.');
    }

    public function test_replies_follow_the_review_they_answer(): void
    {
        $id = $this->recipe('borscht');
        $first = $this->comment($id, 'Анна', ['comment_date' => '2026-01-01 10:00:00']);
        $this->comment($id, 'Борис', ['comment_date' => '2026-01-05 10:00:00']);
        $reply = $this->comment($id, 'Автор', ['comment_date' => '2026-01-02 10:00:00', 'comment_parent' => $first]);
        $this->comment($id, 'Анна', ['comment_date' => '2026-01-03 10:00:00', 'comment_parent' => $reply]);
        // A reply to a review that isn't shown becomes a review of its own.
        $pending = $this->comment($id, 'Спам', ['comment_approved' => '0']);
        $this->comment($id, 'Виктор', ['comment_date' => '2026-01-04 10:00:00', 'comment_parent' => $pending]);

        $reviews = $this->getJson('/api/recipes/borscht')->json('data.reviews');

        $this->assertSame(
            [['Борис', null], ['Виктор', null], ['Анна', null], ['Автор', 'Анна'], ['Анна', 'Автор']],
            array_map(fn ($r) => [$r['name'], $r['replyTo'] ?? null], $reviews),
        );
    }

    public function test_video_reviews(): void
    {
        $id = $this->makePost('video', 'tom-yam', [], ['youtube_id' => 'abc']);

        $this->postJson('/api/videos/tom-yam/comments', ['name' => 'Анна', 'text' => 'Супер видео'])->assertCreated();
        $this->assertSame(1, $this->site()->table('comments')->where('comment_post_ID', $id)->count());
    }
}
