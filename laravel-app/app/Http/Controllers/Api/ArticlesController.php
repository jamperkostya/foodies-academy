<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Articles;
use App\Services\Comments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** News and the blog: the same API over two WordPress post types. */
abstract class ArticlesController extends Controller
{
    private const CACHE_MINUTES = 10;

    /** Cache key prefix. */
    protected const NAME = '';

    public function __construct(protected Articles $articles) {}

    // GET ?limit=7
    public function index(Request $request): JsonResponse
    {
        $limit = (int) ($request->validate(['limit' => 'integer|min:1|max:200'])['limit'] ?? 7);

        return $this->cached(static::NAME.":latest:$limit", fn () => $this->articles->getLatest($limit));
    }

    // GET /slugs
    public function slugs(): JsonResponse
    {
        return $this->cached(static::NAME.':slugs', fn () => $this->articles->getSlugs());
    }

    // GET /{slug}
    public function show(string $slug): JsonResponse
    {
        $item = Cache::remember(static::NAME.":slug:$slug", now()->addMinutes(self::CACHE_MINUTES), fn () => $this->articles->getBySlug($slug));
        abort_if(! $item, 404, 'Not found');

        return response()->json(['data' => $item]);
    }

    // POST /{slug}/comments {name, text}: pending until approved in WordPress.
    public function comment(Request $request, string $slug, Comments $comments): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|min:2|max:60', 'text' => 'required|string|min:3|max:3000']);
        $postId = $this->articles->getId($slug);
        abort_if(! $postId, 404, 'Not found');

        $comments->addPending($postId, strip_tags(trim($data['name'])), strip_tags(trim($data['text'])), null,
            (string) $request->ip(), (string) $request->userAgent());

        return response()->json(['message' => 'Комментарий отправлен на модерацию'], 201);
    }

    private function cached(string $key, callable $load): JsonResponse
    {
        return response()->json(['data' => Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), $load)]);
    }
}
