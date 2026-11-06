<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Comments;
use App\Services\Recipes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RecipesController extends Controller
{
    private const CACHE_MINUTES = 10;

    public function __construct(private Recipes $recipes) {}

    // GET /api/recipes/top?limit=10
    public function top(Request $request)//: JsonResponse
    {
        $data = $request->validate([
            'limit' => 'integer|min:1|max:50',
        ]);
        $limit = (int) ($data['limit'] ?? 10);
        return $this->cached(
            "recipes:top:$limit",
            fn () => $this->recipes->getTopRecipes($limit),
        );
    }

    //     // GET /api/recipes/top?limit=10&lang=ru
    public function new(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => 'integer|min:1|max:50',
        ]);
        $limit = (int) ($data['limit'] ?? 10);

        return $this->cached(
            "recipes:new:$limit",
            fn () => $this->recipes->getNewRecipes($limit),
        );
    }

    private function cached(string $key, callable $load)//: JsonResponse
    {
        $recipes = Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), $load);;
        return response()->json(['data' => $recipes]);
    }

    // GET /api/recipes/featured?limit=10 (marked as "top" in WordPress)
    public function featured(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => 'integer|min:1|max:50',
        ]);
        $limit = (int) ($data['limit'] ?? 10);

        return $this->cached(
            "recipes:featured:$limit",
            fn () => $this->recipes->getFeaturedRecipes($limit),
        );
    }

    // GET /api/recipes/by-ingredients?ingredients=5&limit=20
    public function byIngredients(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ingredients' => 'integer|min:1|max:10',
            'limit' => 'integer|min:1|max:50',
        ]);
        $ingredients = (int) ($data['ingredients'] ?? 5);
        $limit = (int) ($data['limit'] ?? 20);

        return $this->cached(
            "recipes:by-ingredients:$ingredients:$limit",
            fn () => $this->recipes->getRecipesByIngredients($ingredients, $limit),
        );
    }

    // GET /api/recipes/search-index: every recipe's title and ingredients for the site search
    public function searchIndex(): JsonResponse
    {
        return $this->cached('recipes:search-index', fn () => $this->recipes->getSearchIndex());
    }

    // GET /api/recipes/slugs
    public function slugs(): JsonResponse
    {
        return $this->cached('recipes:slugs', fn () => $this->recipes->getSlugs());
    }

    // GET /api/recipes/sitemap: every recipe with its last change and photos
    public function sitemap(): JsonResponse
    {
        return $this->cached('recipes:sitemap', fn () => $this->recipes->getSitemap());
    }

    // POST /api/recipes/{slug}/comments  {name, text, rating?}
    // Saved as pending: it appears on the site after approval in the WordPress admin.
    public function comment(Request $request, string $slug, Comments $comments): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:60',
            'text' => 'required|string|min:3|max:3000',
            'rating' => 'nullable|integer|min:1|max:5',
        ]);
        $postId = $this->recipes->getRecipeId($slug);
        abort_if(! $postId, 404, 'Recipe not found');

        $comments->addPending(
            $postId,
            strip_tags(trim($data['name'])),
            strip_tags(trim($data['text'])),
            $data['rating'] ?? null,
            (string) $request->ip(),
            (string) $request->userAgent(),
        );

        return response()->json(['message' => 'Комментарий отправлен на модерацию'], 201);
    }

    // GET /api/recipes/{slug}
    public function show(string $slug): JsonResponse
    {
        $recipe = Cache::remember("recipes:slug:$slug", now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->recipes->getRecipeBySlug($slug));

        abort_if(! $recipe, 404, 'Recipe not found');

        return response()->json(['data' => $recipe]);
    }
}
