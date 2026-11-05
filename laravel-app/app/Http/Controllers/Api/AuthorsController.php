<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Authors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AuthorsController extends Controller
{
    private const CACHE_MINUTES = 10;

    public function __construct(private Authors $authors) {}

    // GET /api/authors
    public function index(): JsonResponse
    {
        $authors = Cache::remember('authors:all', now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->authors->getAll());

        return response()->json(['data' => $authors]);
    }

    // GET /api/authors/{slug}?page=2 (24 recipes a page)
    public function show(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'page' => 'integer|min:1',
        ]);
        $page = (int) ($data['page'] ?? 1);

        $author = Cache::remember("authors:slug:$slug:page:$page", now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->authors->getBySlug($slug, $page));

        abort_if(! $author, 404, 'Author not found');

        return response()->json(['data' => $author]);
    }
}
