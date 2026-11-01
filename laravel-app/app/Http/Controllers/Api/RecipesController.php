<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Recipes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RecipesController extends Controller
{
    private const CACHE_MINUTES = 10;

    public function __construct(private Recipes $recipes) {}

    // GET /api/recipes/top?limit=10&lang=ru
    public function top(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => 'integer|min:1|max:50',
            'lang' => 'in:ru,en',
        ]);
        $limit = (int) ($data['limit'] ?? 10);
        $lang = $data['lang'] ?? 'ru';

        return $this->cached(
            "recipes:top:$lang:$limit",
            fn () => $this->recipes->getTopRecipes($limit, $lang),
        );
    }

    // GET /api/recipes/new?limit=10&lang=ru
    public function new(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => 'integer|min:1|max:50',
            'lang' => 'in:ru,en',
        ]);
        $limit = (int) ($data['limit'] ?? 10);
        $lang = $data['lang'] ?? 'ru';

        return $this->cached(
            "recipes:new:$lang:$limit",
            fn () => $this->recipes->getNewRecipes($limit, $lang),
        );
    }

    // GET /api/recipes/category/{id}?limit=12&page=1&lang=ru
    public function byCategory(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'limit' => 'integer|min:1|max:50',
            'page' => 'integer|min:1',
            'lang' => 'in:ru,en',
        ]);
        $limit = (int) ($data['limit'] ?? 12);
        $page = (int) ($data['page'] ?? 1);
        $lang = $data['lang'] ?? 'ru';

        return $this->cached(
            "recipes:category:$id:$lang:$limit:$page",
            fn () => $this->recipes->getRecipesByCategoryId($id, $limit, $page, $lang),
        );
    }

    private function cached(string $key, callable $load): JsonResponse
    {
        $recipes = Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), $load);

        return response()->json(['data' => $recipes]);
    }
}
