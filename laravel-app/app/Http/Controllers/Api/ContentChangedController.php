<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Seo;
use App\Services\SiteRebuild;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentChangedController extends Controller
{
    // WordPress post types => the REST base the Seo lookups use; the others are their own.
    private const REST_BASES = ['post' => 'posts'];

    /**
     * POST /api/internal/content-changed {reason, changed: [{post_type, slug, id} | {taxonomy, slug}]}:
     * from WordPress, with the shared X-Foodies-Secret.
     */
    public function __invoke(Request $request, SiteRebuild $rebuild, Seo $seo): JsonResponse
    {
        $secret = (string) config('services.wordpress.api_secret');
        abort_unless(strlen($secret) >= 32 && hash_equals($secret, (string) $request->header('X-Foodies-Secret')), 403);

        foreach ((array) $request->input('changed', []) as $item) {
            $slug = (string) ($item['slug'] ?? '');
            $type = (string) ($item['post_type'] ?? '');
            if ($type === 'page' && ($id = (int) ($item['id'] ?? 0))) {
                $seo->forget("pages/$id");
            } elseif ($type !== '' && $slug !== '') {
                $seo->forget(self::REST_BASES[$type] ?? $type, ['slug' => $slug]);
            } elseif (($taxonomy = (string) ($item['taxonomy'] ?? '')) !== '' && $slug !== '') {
                $seo->forget($taxonomy, ['slug' => $slug]);
            }
        }
        $rebuild->contentChanged(mb_substr((string) $request->input('reason', 'content changed'), 0, 200));

        return response()->json(null, 202);
    }
}
