<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NewsController extends Controller
{
    private const CACHE_MINUTES = 10;

    public function __construct(private News $news) {}

    // GET /api/news?limit=7
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => 'integer|min:1|max:50',
        ]);
        $limit = (int) ($data['limit'] ?? 7);

        return $this->cached("news:latest:$limit", fn () => $this->news->getLatest($limit));
    }

    // GET /api/news/slugs
    public function slugs(): JsonResponse
    {
        return $this->cached('news:slugs', fn () => $this->news->getSlugs());
    }

    // GET /api/news/{slug}
    public function show(string $slug): JsonResponse
    {
        $item = Cache::remember("news:slug:$slug", now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->news->getBySlug($slug));

        abort_if(! $item, 404, 'News not found');

        return response()->json(['data' => $item]);
    }

    private function cached(string $key, callable $load): JsonResponse
    {
        return response()->json(['data' => Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), $load)]);
    }
}
