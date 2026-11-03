<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SiteRebuild;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentChangedController extends Controller
{
    // POST /api/internal/content-changed {reason}: from WordPress, with the shared X-Foodies-Secret.
    public function __invoke(Request $request, SiteRebuild $rebuild): JsonResponse
    {
        $secret = (string) config('services.wordpress.api_secret');
        abort_unless(strlen($secret) >= 32 && hash_equals($secret, (string) $request->header('X-Foodies-Secret')), 403);

        $rebuild->contentChanged(mb_substr((string) $request->input('reason', 'content changed'), 0, 200));

        return response()->json(null, 202);
    }
}
