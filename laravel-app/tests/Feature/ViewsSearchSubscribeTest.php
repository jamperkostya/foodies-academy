<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\SiteTestCase;

class ViewsSearchSubscribeTest extends SiteTestCase
{
    public function test_a_view_is_counted_for_post_views_counter_and_the_site(): void
    {
        Carbon::setTestNow('2026-11-03 21:30:00'); // 23:30 site time (gmt_offset 2)
        $id = $this->recipe('borscht');
        $this->site()->table('post_views_realtime')->insert([
            'post_id' => $id, 'view_type' => 'normal', 'output_type' => 'content', 'post_views_today' => 0, 'post_views_week' => 0,
            'post_views_month' => 0, 'post_views_halfyear' => 0, 'post_views_year' => 0, 'post_views_total' => 100, 'latest_view_time' => '2023-01-01 00:00:00',
        ]);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone)')->postJson('/api/recipes/borscht/view')->assertNoContent();
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone)')->postJson('/api/recipes/borscht/view');

        $this->assertSame(
            ['20261103' => 2, '202645' => 2, '202611' => 2, '2026' => 2, 'total' => 2],
            $this->site()->table('post_views')->where('id', $id)->orderBy('type')->pluck('count', 'period')->map(fn ($n) => (int) $n)->all(),
        );
        $this->assertSame(102, (int) $this->site()->table('post_views_realtime')->where('post_id', $id)->value('post_views_total'));
        // The card shows it.
        $this->getJson('/api/recipes/new')->assertJsonPath('data.0.views.count', 102);
    }

    public function test_bots_are_not_counted(): void
    {
        $id = $this->recipe('borscht');

        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1)')->postJson('/api/recipes/borscht/view')->assertNoContent();
        $this->postJson('/api/recipes/nope/view')->assertNotFound();

        $this->assertSame(0, $this->site()->table('post_views')->where('id', $id)->count());
    }

    public function test_searches_go_to_search_meter(): void
    {
        $this->postJson('/api/search-log', ['q' => 'борщ, с пампушками', 'hits' => 4])->assertNoContent();
        $this->postJson('/api/search-log', ['q' => 'борщ, с пампушками', 'hits' => 5])->assertNoContent();

        $this->assertSame(2, $this->site()->table('searchmeter_recent')->where('terms', 'борщ, с пампушками')->count());
        $summary = $this->site()->table('searchmeter')->first();
        $this->assertSame(['борщ с пампушками', 2, 5], [$summary->terms, (int) $summary->count, (int) $summary->last_hits]);
        $this->postJson('/api/search-log', ['hits' => 1])->assertUnprocessable();
    }

    public function test_search_history_is_trimmed_like_search_meter(): void
    {
        $rows = array_map(fn ($i) => ['terms' => "q$i", 'datetime' => Carbon::parse('2026-01-01')->addMinutes($i), 'hits' => 0, 'details' => ''], range(1, 601));
        $this->site()->table('searchmeter_recent')->insert($rows);

        $this->postJson('/api/search-log', ['q' => 'new', 'hits' => 0]);

        $this->assertSame(501, $this->site()->table('searchmeter_recent')->count());
    }

    public function test_subscription_goes_through_contact_form_7(): void
    {
        Http::fake(['wordpress.test/wp-json/contact-form-7/*' => Http::response(['status' => 'mail_sent'])]);

        $this->postJson('/api/subscribe', ['name' => 'Анна', 'email' => 'anna@example.test'])->assertCreated();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/contact-form-7/v1/contact-forms/77/feedback')
            && $request->isMultipart()
            && collect($request->data())->pluck('contents', 'name')->only(['username', 'email', '_wpcf7_unit_tag'])->all()
                === ['username' => 'Анна', 'email' => 'anna@example.test', '_wpcf7_unit_tag' => 'wpcf7-f77-o1']);
    }

    public function test_each_list_goes_to_its_own_form(): void
    {
        config(['services.wordpress.subscribe_forms' => ['newsletter' => 77, 'book' => 91]]);
        Http::fake(['wordpress.test/wp-json/contact-form-7/*' => Http::response(['status' => 'mail_sent'])]);

        $this->postJson('/api/subscribe', ['name' => 'Анна', 'email' => 'anna@example.test', 'list' => 'book'])->assertCreated();
        $this->postJson('/api/subscribe', ['name' => 'Анна', 'email' => 'anna@example.test', 'list' => 'newsletter'])->assertCreated();

        $forms = Http::recorded()->map(fn ($pair) => [
            basename(dirname($pair[0]->url())),
            collect($pair[0]->data())->pluck('contents', 'name')->only(['_wpcf7', '_wpcf7_unit_tag'])->all(),
        ])->all();
        $this->assertSame([
            ['91', ['_wpcf7' => 91, '_wpcf7_unit_tag' => 'wpcf7-f91-o1']],
            ['77', ['_wpcf7' => 77, '_wpcf7_unit_tag' => 'wpcf7-f77-o1']],
        ], $forms);
    }

    public function test_an_unknown_list_is_rejected(): void
    {
        Http::fake();

        $this->postJson('/api/subscribe', ['name' => 'Анна', 'email' => 'anna@example.test', 'list' => 'vip'])
            ->assertUnprocessable()->assertJsonValidationErrors('list');
        Http::assertNothingSent();
    }

    public function test_subscription_saved_even_if_the_mail_fails(): void
    {
        Http::fake(['wordpress.test/*' => Http::response(['status' => 'mail_failed'])]);

        $this->postJson('/api/subscribe', ['name' => 'Анна', 'email' => 'anna@example.test'])->assertCreated();
    }

    public function test_subscription_errors(): void
    {
        Http::fake(['wordpress.test/*' => Http::sequence()
            ->push(['status' => 'validation_failed', 'invalid_fields' => [['field' => 'username', 'message' => 'Заполните имя']]])
            ->push(['status' => 'spam'])]);

        $this->postJson('/api/subscribe', ['name' => 'Анна', 'email' => 'anna@example.test'])
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'Заполните имя');
        $this->postJson('/api/subscribe', ['name' => 'Анна', 'email' => 'anna@example.test'])->assertStatus(502);
        $this->postJson('/api/subscribe', ['name' => 'Анна', 'email' => 'not-an-email'])->assertUnprocessable();
    }
}
