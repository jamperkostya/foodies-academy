<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ingredients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class IngredientsController extends Controller
{
    private const CACHE_MINUTES = 10;

    public function __construct(private Ingredients $ingredients) {}

    // GET /api/ingredients: every ingredient with a recipe (the site builds a page for each)
    public function index(): JsonResponse
    {
        $ingredients = Cache::remember('ingredients:all', now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->ingredients->getAll());

        return response()->json(['data' => $ingredients]);
    }

    // GET /api/ingredients/popular?limit=5
    public function popular(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => 'integer|min:1|max:50',
        ]);
        $limit = (int) ($data['limit'] ?? 5);

        $ingredients = Cache::remember("ingredients:popular:$limit", now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->ingredients->getPopular($limit));

        return response()->json(['data' => $ingredients]);
    }

    // GET /api/ingredients/{slug}?page=2 (24 recipes a page)
    public function show(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'page' => 'integer|min:1',
        ]);
        $page = (int) ($data['page'] ?? 1);

        $ingredient = Cache::remember("ingredients:slug:$slug:page:$page", now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->ingredients->getBySlug($slug, $page));

        abort_if(! $ingredient, 404, 'Ingredient not found');

        return response()->json(['data' => $ingredient]);
    }
}
