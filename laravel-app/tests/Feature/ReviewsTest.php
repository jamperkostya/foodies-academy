<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
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

    public function test_review_approved_outside_wordpress_counts_on_the_page_and_in_lists(): void
    {
        $id = $this->recipe('turkey');
        $this->postJson('/api/recipes/turkey/comments', ['name' => 'test', 'text' => 'test', 'rating' => 5])->assertCreated();
        // Approved straight in the database: WordPress leaves posts.comment_count at 0.
        $this->site()->table('comments')->where('comment_post_ID', $id)->update(['comment_approved' => '1']);
        $this->assertSame(0, (int) $this->site()->table('posts')->where('ID', $id)->value('comment_count'));
        Cache::flush();

        $this->getJson('/api/recipes/turkey')
            ->assertJsonCount(1, 'data.reviews')
            ->assertJsonPath('data.comments', 1)
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.ratingCount', 1);
        $card = collect($this->getJson('/api/recipes/new')->json('data'))->firstWhere('slug', 'turkey');
        $this->assertSame(['comments' => 1, 'rating' => 5, 'ratingCount' => 1], ['comments' => $card['comments'], 'rating' => $card['rating'], 'ratingCount' => $card['ratingCount']]);
    }

    public function test_pending_and_spam_comments_are_not_counted(): void
    {
        $id = $this->recipe('borscht');
        $this->comment($id, 'Анна', [], 4);
        $this->comment($id, 'Боб', ['comment_approved' => '0'], 1);
        $this->comment($id, 'Спам', ['comment_approved' => 'spam']);
        // A pingback is not a review.
        $this->comment($id, 'blog.example', ['comment_type' => 'pingback']);

        $this->getJson('/api/recipes/borscht')
            ->assertJsonPath('data.comments', 1)
            ->assertJsonPath('data.rating', 4);
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
