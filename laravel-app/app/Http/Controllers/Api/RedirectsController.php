<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Redirects;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class RedirectsController extends Controller
{
    // GET /api/redirects → {ids: [{id, to}], slugs: [{from, to}]}: old WordPress addresses for the site's .htaccess
    public function __invoke(Redirects $redirects): JsonResponse
    {
        return response()->json(['data' => Cache::remember('redirects', now()->addMinutes(10), fn () => $redirects->get())]);
    }
}
