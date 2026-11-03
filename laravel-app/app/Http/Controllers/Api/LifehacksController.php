<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Comments;
use App\Services\Lifehacks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LifehacksController extends Controller
{
    private const CACHE_MINUTES = 10;

    public function __construct(private Lifehacks $lifehacks) {}

    // GET /api/lifehacks → all, newest first
    public function index(): JsonResponse
    {
        return response()->json(['data' => Cache::remember('lifehacks:all', now()->addMinutes(self::CACHE_MINUTES), fn () => $this->lifehacks->getAll())]);
    }

    // GET /api/lifehacks/{slug}
    public function show(string $slug): JsonResponse
    {
        $lifehack = Cache::remember("lifehacks:slug:$slug", now()->addMinutes(self::CACHE_MINUTES), fn () => $this->lifehacks->getBySlug($slug));
        abort_if(! $lifehack, 404, 'Lifehack not found');

        return response()->json(['data' => $lifehack]);
    }

    // POST /api/lifehacks/{slug}/comments {name, text}: pending until approved in WordPress.
    public function comment(Request $request, string $slug, Comments $comments): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|min:2|max:60', 'text' => 'required|string|min:3|max:3000']);
        $postId = $this->lifehacks->getId($slug);
        abort_if(! $postId, 404, 'Lifehack not found');

        $comments->addPending($postId, strip_tags(trim($data['name'])), strip_tags(trim($data['text'])), null,
            (string) $request->ip(), (string) $request->userAgent());

        return response()->json(['message' => 'Комментарий отправлен на модерацию'], 201);
    }
}
