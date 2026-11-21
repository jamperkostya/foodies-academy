<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SiteStats;
use App\Support\Lang;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class StatsController extends Controller
{
    // GET /api/stats → {recipes, ratings, views, comments}
    public function show(SiteStats $stats): JsonResponse
    {
        return response()->json(['data' => Cache::remember(Lang::key('site:stats'), now()->addHour(), fn () => $stats->get())]);
    }
}
