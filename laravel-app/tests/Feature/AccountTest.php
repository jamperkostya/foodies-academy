<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\SiteTestCase;

class AccountTest extends SiteTestCase
{
    private const WP = 'wordpress.test/wp-json/foodies/v1/';

    /** What the faked WordPress returns for GET users/{id}; a test may change it. */
    private array $userFields = [];

    private function wpUser(int $id, array $fields = []): array
    {
        return $fields + ['id' => $id, 'login' => 'anna', 'email' => 'anna@example.test', 'name' => 'Анна', 'firstName' => 'Анна', 'lastName' => '', 'description' => '', 'registered' => '2026-01-01T00:00:00+00:00'];
    }

    /** Logs in a WordPress user (faked) and returns the auth header. */
    private function logIn(int $id): array
    {
        Http::fake([
            self::WP.'login' => Http::response($this->wpUser($id)),
            self::WP."users/$id" => fn () => Http::response($this->wpUser($id, $this->userFields)),
        ]);
        $token = $this->postJson('/api/auth/login', ['email' => 'anna@example.test', 'password' => 'secret-pass'])->json('data.token');

        return ['Authorization' => "Bearer $token"];
    }

    public function test_sign_up_creates_a_wordpress_user_and_logs_in(): void
    {
        Http::fake([
            self::WP.'register' => Http::response($this->wpUser(7)),
            self::WP.'users/7' => Http::response($this->wpUser(7)),
        ]);

        $token = $this->postJson('/api/auth/register', ['name' => 'Анна', 'email' => 'anna@example.test', 'password' => 'secret-pass'])
            ->assertCreated()
            ->assertJsonPath('data.user.id', 7)
            ->json('data.token');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/register')
            && $request->header('X-Foodies-Secret')[0] === config('services.wordpress.api_secret')
            && $request['email'] === 'anna@example.test');
        $this->withHeader('Authorization', "Bearer $token")->getJson('/api/me')->assertOk()->assertJsonPath('data.user.name', 'Анна');
        // Only the hash of the token is stored.
        $this->assertSame(hash('sha256', $token), DB::table('api_tokens')->value('token_hash'));
    }

    public function test_wordpress_errors_show_up_under_the_field(): void
    {
        Http::fake([self::WP.'register' => Http::response(['code' => 'email_exists', 'message' => 'Этот e-mail уже зарегистрирован', 'data' => ['status' => 422, 'field' => 'email']], 422)]);

        $this->postJson('/api/auth/register', ['name' => 'Анна', 'email' => 'anna@example.test', 'password' => 'secret-pass'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Этот e-mail уже зарегистрирован');
        $this->postJson('/api/auth/register', ['name' => 'Анна', 'email' => 'anna@example.test', 'password' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_wrong_password_and_a_refused_api_key_are_told_apart(): void
    {
        Http::fake([self::WP.'login' => Http::sequence()
            ->push(['code' => 'invalid_credentials', 'message' => 'x', 'data' => ['status' => 401]], 401)
            ->push(['code' => 'rest_forbidden', 'message' => 'x', 'data' => ['status' => 401]], 401)]);

        $this->postJson('/api/auth/login', ['email' => 'anna@example.test', 'password' => 'nope'])
            ->assertUnauthorized()->assertJsonPath('message', 'Неверный e-mail или пароль');
        // A misconfigured key is a server error, not "wrong password".
        $this->postJson('/api/auth/login', ['email' => 'anna@example.test', 'password' => 'nope'])->assertServerError();
    }

    public function test_account_routes_need_a_valid_token_and_logout_revokes_it(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer nonsense')->getJson('/api/me')->assertUnauthorized();

        $auth = $this->logIn(7);
        $this->withHeaders($auth)->getJson('/api/me')->assertOk();
        $this->withHeaders($auth)->postJson('/api/auth/logout')->assertNoContent();
        $this->withHeaders($auth)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_tokens_unused_for_90_days_expire(): void
    {
        $auth = $this->logIn(7);
        DB::table('api_tokens')->update(['last_used_at' => now()->subDays(91)]);

        $this->withHeaders($auth)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_profile_update_is_passed_to_wordpress(): void
    {
        $auth = $this->logIn(7);
        $this->userFields = ['name' => 'Анна К.'];

        $this->withHeaders($auth)->postJson('/api/me', ['name' => 'Анна К.', 'lastName' => 'Котова'])
            ->assertOk()->assertJsonPath('data.name', 'Анна К.');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/users/7')
            && $request['lastName'] === 'Котова');
        $this->withHeaders($auth)->postJson('/api/me', ['email' => 'bad'])->assertUnprocessable();
    }

    public function test_favorites(): void
    {
        $this->recipe('borscht', ['post_title' => 'Борщ']);
        $pasta = $this->recipe('pasta', ['post_title' => 'Паста']);
        $auth = $this->logIn(7);

        $this->withHeaders($auth)->putJson('/api/me/favorites/borscht')->assertOk();
        $this->withHeaders($auth)->putJson('/api/me/favorites/pasta')->assertOk();
        $this->withHeaders($auth)->putJson('/api/me/favorites/pasta')->assertJsonCount(2, 'data.favorites');
        $this->withHeaders($auth)->putJson('/api/me/favorites/nope')->assertNotFound();

        // Last added first, stored in the WordPress user's meta.
        $this->withHeaders($auth)->getJson('/api/me/favorites')->assertJsonPath('data.*.slug', ['pasta', 'borscht']);
        $this->assertSame(2, $this->site()->table('usermeta')->where(['user_id' => 7, 'meta_key' => 'foodies_favorite'])->count());

        $this->withHeaders($auth)->deleteJson('/api/me/favorites/borscht')->assertJsonPath('data.favorites', [$pasta]);
        $this->withHeaders($auth)->getJson('/api/me')->assertJsonPath('data.favorites', [$pasta]);
    }

    public function test_follows_and_new_recipes_since_the_previous_visit(): void
    {
        $mariana = $this->user('mariana', 'Мариана');
        $oleg = $this->user('oleg', 'Олег');
        $this->recipe('old', ['post_author' => $mariana, 'post_date' => '2026-01-01 10:00:00']);
        $this->recipe('olegs', ['post_author' => $oleg, 'post_date' => '2026-01-20 10:00:00']);
        $auth = $this->logIn(7);

        Carbon::setTestNow('2026-01-10 12:00:00');
        $this->withHeaders($auth)->putJson('/api/me/follows/mariana')->assertJsonPath('data.follows', [$mariana]);
        $this->withHeaders($auth)->putJson('/api/me/follows/nobody')->assertNotFound();
        $this->withHeaders($auth)->getJson('/api/me'); // first visit

        // A request 10 minutes later is the same visit: nothing new.
        Carbon::setTestNow('2026-01-10 12:10:00');
        $this->withHeaders($auth)->getJson('/api/me');
        $this->withHeaders($auth)->getJson('/api/me/follows')->assertJsonCount(0, 'data.newRecipes.recipes');

        // Mariana publishes while the visitor is away.
        $this->recipe('new', ['post_author' => $mariana, 'post_date' => '2026-01-15 10:00:00']);

        // A new visit days later: Mariana's recipe since the last visit, not Oleg's (not followed).
        Carbon::setTestNow('2026-01-21 09:00:00');
        $this->withHeaders($auth)->getJson('/api/me');
        $this->withHeaders($auth)->getJson('/api/me/follows')
            ->assertJsonPath('data.authors.0.name', 'Мариана')
            ->assertJsonPath('data.newRecipes.recipes.*.slug', ['new'])
            ->assertJsonPath('data.newRecipes.since', '2026-01-10T12:10:00+00:00');

        $this->withHeaders($auth)->deleteJson('/api/me/follows/mariana')->assertJsonPath('data.follows', []);
    }
}
