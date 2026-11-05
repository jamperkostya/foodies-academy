<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Totals for the "Нам доверяют" block: {recipes, ratings, views, comments}. */
class SiteStats
{
    public function get(): array
    {
        $db = DB::connection('site');
        $prefix = $db->getTablePrefix();

        $recipeIds = $db->table('posts as p')
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            ->select('p.ID');

        $approved = $db->table('comments')->where('comment_approved', '1')->whereIn('comment_type', ['comment', '']);

        // Every rating a visitor gave: likes and dislikes (Posts Like Dislike), stars in approved
        // reviews, stars for authors.
        $votes = (int) $db->table('postmeta')->whereIn('meta_key', ['pld_like_count', 'pld_dislike_count'])->sum(DB::raw("CAST({$prefix}postmeta.meta_value AS UNSIGNED)"));
        $stars = (clone $approved)->join('commentmeta as m', function ($join) {
            $join->on('m.comment_id', '=', 'comments.comment_ID')->where('m.meta_key', 'rating');
        })->count();
        $authorStars = (int) $db->table('usermeta')->where('meta_key', 'foodies_rating_count')->sum(DB::raw("CAST({$prefix}usermeta.meta_value AS UNSIGNED)"));

        return [
            'recipes' => (clone $recipeIds)->count(),
            'ratings' => $votes + $stars + $authorStars,
            // People, not robots; the full recipe page, not its excerpt in lists.
            'views' => (int) $db->table('post_views_realtime')
                ->whereIn('post_id', $recipeIds)
                ->where('view_type', 'normal')
                ->where('output_type', 'content')
                ->sum('post_views_total'),
            'comments' => (clone $approved)->count(),
        ];
    }
}
