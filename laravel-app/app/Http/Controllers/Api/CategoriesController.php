<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Categories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CategoriesController extends Controller
{
    private const CACHE_MINUTES = 10;

    private const TYPES = 'in:course,country';

    public function __construct(private Categories $categories) {}

    // GET /api/categories?type=course&limit=10 (no limit = all categories of the type)
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|'.self::TYPES,
            'limit' => 'integer|min:1|max:100',
        ]);
        $limit = isset($data['limit']) ? (int) $data['limit'] : null;

        return $this->cached(
            "categories:type:{$data['type']}:".($limit ?? 'all'),
            fn () => $this->categories->getByType($data['type'], $limit),
        );
    }

    // GET /api/categories/popular?type=course&limit=10
    public function popular(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|'.self::TYPES,
            'limit' => 'integer|min:1|max:50',
        ]);
        $limit = (int) ($data['limit'] ?? 10);

        return $this->cached(
            "categories:popular:{$data['type']}:$limit",
            fn () => $this->categories->getPopular($data['type'], $limit),
        );
    }

    // GET /api/categories/search?name=суп&type=course (type is optional)
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:50',
            'type' => self::TYPES,
        ]);

        // Search results are not cached: there are too many possible queries.
        return response()->json([
            'data' => $this->categories->getByName($data['name'], $data['type'] ?? null),
        ]);
    }

    // GET /api/categories/{slug}?page=2 (24 recipes a page): a category or a country with its recipes
    public function show(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'page' => 'integer|min:1',
        ]);
        $page = (int) ($data['page'] ?? 1);

        $category = Cache::remember("categories:slug:$slug:page:$page", now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->categories->getBySlug($slug, $page));

        abort_if(! $category, 404, 'Category not found');

        return response()->json(['data' => $category]);
    }

    private function cached(string $key, callable $load): JsonResponse
    {
        $categories = Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), $load);

        return response()->json(['data' => $categories]);
    }
}
