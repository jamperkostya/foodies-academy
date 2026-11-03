<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pages;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PagesController extends Controller
{
    private const CACHE_MINUTES = 10;

    // GET /api/pages → [{slug, title}] of the pages shown on the site
    public function index(Pages $pages): JsonResponse
    {
        return response()->json(['data' => Cache::remember('pages:site', now()->addMinutes(self::CACHE_MINUTES), fn () => $pages->getSitePages())]);
    }

    // GET /api/pages/{slug}: "home" is the front page.
    public function show(string $slug, Pages $pages): JsonResponse
    {
        $page = Cache::remember("pages:slug:$slug", now()->addMinutes(self::CACHE_MINUTES), fn () => $pages->getBySlug($slug));
        abort_if(! $page, 404, 'Page not found');

        return response()->json(['data' => $page]);
    }
}
