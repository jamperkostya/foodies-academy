<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthorRatings;
use App\Services\Authors;
use App\Services\Likes;
use App\Services\PostViews;
use App\Services\Recipes;
use App\Services\SearchLog;
use App\Services\Videos;
use App\Services\Subscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** What visitors do on the site: likes, searches, newsletter sign-ups. Saved into WordPress plugins' data. */
class EngagementController extends Controller
{
    // GET /api/{recipes|videos}/{slug}/likes → {like, dislike}. Not cached: the page shows live numbers.
    public function likes(string $type, string $slug, Likes $likes): JsonResponse
    {
        return response()->json(['data' => $likes->get($this->postId($type, $slug))]);
    }

    // POST /api/{recipes|videos}/{slug}/likes {from: like|dislike|null, to: like|dislike|null}
    // `from` is the visitor's previous vote (the browser remembers it), `to` the new one.
    public function vote(Request $request, string $type, string $slug, Likes $likes): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', Rule::in(['like', 'dislike'])],
            'to' => ['nullable', Rule::in(['like', 'dislike']), 'different:from'],
        ]);

        $counts = $likes->vote($this->postId($type, $slug), $data['from'] ?? null, $data['to'] ?? null);

        return response()->json(['data' => $counts]);
    }

    // POST /api/{recipes|videos}/{slug}/view: the page was opened (the browser sends it at most
    // once a day per page). Bots are not counted.
    public function view(Request $request, string $type, string $slug, PostViews $views): JsonResponse
    {
        $postId = $this->postId($type, $slug);
        if (! PostViews::isBot((string) $request->userAgent())) {
            $views->count($postId);
        }

        return response()->json(null, 204);
    }

    // POST /api/search-log {q, hits}: a search made on /search, for Search Meter's statistics.
    public function search(Request $request, SearchLog $log): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|max:200',
            'hits' => 'required|integer|min:0|max:100000',
        ]);
        $log->record(trim(strip_tags($data['q'])), (int) $data['hits']);

        return response()->json(null, 204);
    }

    // POST /api/subscribe {name, email}: goes to Contact Form 7, saved by CFDB7.
    public function subscribe(Request $request, Subscriptions $subscriptions): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:60',
            'email' => 'required|email|max:120',
        ]);

        try {
            $errors = $subscriptions->subscribe(strip_tags(trim($data['name'])), trim($data['email']));
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'Не удалось оформить подписку, попробуйте позже'], 502);
        }
        if ($errors) {
            // CF7 field names → ours, so the form can point at the field.
            throw ValidationException::withMessages(collect($errors)
                ->mapWithKeys(fn ($message, $field) => [$field === 'username' ? 'name' : $field => $message])
                ->all());
        }

        return response()->json(['message' => 'Вы подписаны'], 201);
    }

    // GET /api/authors/{slug}/rating → {average, count}
    public function authorRating(string $slug, Authors $authors, AuthorRatings $ratings): JsonResponse
    {
        return response()->json(['data' => $ratings->get($this->authorId($slug, $authors))]);
    }

    // POST /api/authors/{slug}/rating {from: 1-5|null, to: 1-5|null}: `from` is the visitor's previous stars.
    public function rateAuthor(Request $request, string $slug, Authors $authors, AuthorRatings $ratings): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|integer|min:1|max:5',
            'to' => 'nullable|integer|min:1|max:5',
        ]);
        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;
        if ($from === $to) {
            return $this->authorRating($slug, $authors, $ratings);
        }

        return response()->json(['data' => $ratings->vote($this->authorId($slug, $authors), $from, $to)]);
    }

    private function authorId(string $slug, Authors $authors): int
    {
        $id = $authors->getId($slug);
        abort_if(! $id, 404, 'Author not found');

        return $id;
    }

    private function postId(string $type, string $slug): int
    {
        $id = $type === 'videos' ? app(Videos::class)->getId($slug) : app(Recipes::class)->getRecipeId($slug);
        abort_if(! $id, 404, 'Not found');

        return $id;
    }
}
