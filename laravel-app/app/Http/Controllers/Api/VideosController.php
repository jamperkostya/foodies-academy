<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Comments;
use App\Services\Videos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VideosController extends Controller
{
    private const CACHE_MINUTES = 10;

    public function __construct(private Videos $videos) {}

    // GET /api/videos?page=N&per_page=24 → {videos, total, page, pages}, newest first
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => 'integer|min:1', 'per_page' => 'integer|min:1|max:50']);
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 24);

        return response()->json(['data' => Cache::remember("videos:page:$page:$perPage", now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->videos->getPage($page, $perPage))]);
    }

    // GET /api/videos/slugs
    public function slugs(): JsonResponse
    {
        return response()->json(['data' => Cache::remember('videos:slugs', now()->addMinutes(self::CACHE_MINUTES), fn () => $this->videos->getSlugs())]);
    }

    // GET /api/videos/{slug}
    public function show(string $slug): JsonResponse
    {
        $video = Cache::remember("videos:slug:$slug", now()->addMinutes(self::CACHE_MINUTES), fn () => $this->videos->getBySlug($slug));
        abort_if(! $video, 404, 'Video not found');

        return response()->json(['data' => $video]);
    }

    // POST /api/videos/{slug}/comments {name, text, rating?}: pending until approved in WordPress.
    public function comment(Request $request, string $slug, Comments $comments): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:60',
            'text' => 'required|string|min:3|max:3000',
            'rating' => 'nullable|integer|min:1|max:5',
        ]);
        $postId = $this->videos->getId($slug);
        abort_if(! $postId, 404, 'Video not found');

        $comments->addPending($postId, strip_tags(trim($data['name'])), strip_tags(trim($data['text'])),
            $data['rating'] ?? null, (string) $request->ip(), (string) $request->userAgent());

        return response()->json(['message' => 'Комментарий отправлен на модерацию'], 201);
    }
}
