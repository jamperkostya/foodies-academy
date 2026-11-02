<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Collections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CollectionsController extends Controller
{
    private const CACHE_MINUTES = 10;

    public function __construct(private Collections $collections) {}

    // GET /api/collections
    public function index(): JsonResponse
    {
        $collections = Cache::remember('collections:all', now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->collections->getAll());

        return response()->json(['data' => $collections]);
    }

    // GET /api/collections/{slug}?page=2 (24 recipes a page)
    public function show(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'page' => 'integer|min:1',
        ]);
        $page = (int) ($data['page'] ?? 1);

        $collection = Cache::remember("collections:slug:$slug:page:$page", now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->collections->getBySlug($slug, $page));

        abort_if(! $collection, 404, 'Collection not found');

        return response()->json(['data' => $collection]);
    }
}
