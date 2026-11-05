<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Recipe authors: WordPress users who have published Russian recipes (posts.post_author). */
class Authors
{
    private ConnectionInterface $db;

    public function __construct(private Recipes $recipes)
    {
        $this->db = DB::connection('site');
    }

    /** Every author with recipes, the most recipes first. */
    public function getAll(): array
    {
        $counts = $this->recipesQuery()
            ->groupBy('p.post_author')
            ->selectRaw('COUNT(*) as recipes')
            ->addSelect('p.post_author')
            ->orderByDesc('recipes')
            ->pluck('recipes', 'post_author');

        $users = $this->db->table('users')->whereIn('ID', $counts->keys())->get()->keyBy('ID');
        $avatars = $this->avatars($counts->keys()->all());

        // Recipes can outlive their author's account: skip authors whose user is gone.
        return $counts->filter(fn ($recipes, $id) => $users->has($id))
            ->map(fn ($recipes, $id) => $this->card($users[$id], (int) $recipes, $avatars[$id] ?? ''))
            ->values()->all();
    }

    /** One author with one page of their recipes (most viewed first) and all their news, or null. */
    public function getBySlug(string $slug, int $page = 1, int $perPage = 24): ?array
    {
        $user = $this->db->table('users')->where('user_nicename', $slug)->first();
        if (! $user) {
            return null;
        }
        $recipes = $this->recipesQuery()->where('p.post_author', $user->ID)
            ->leftJoin('post_views_realtime as v', function ($join) {
                $join->on('v.post_id', '=', 'p.ID')
                    ->where('v.view_type', 'normal')
                    ->where('v.output_type', 'content');
            })
            ->orderByDesc('v.post_views_total');
        $list = $this->recipes->paginate($recipes, $page, $perPage);
        if ($list['total'] === 0) {
            return null;
        }

        // Average of the recipes' own ratings, ignoring unrated ones.
        $ratings = $this->db->table('postmeta')
            ->whereIn('post_id', $this->recipesQuery()->where('p.post_author', $user->ID)->pluck('p.ID'))
            ->where('meta_key', 'recipe_rating')
            ->pluck('meta_value')
            ->map(fn ($value) => (float) $value)
            ->filter();

        $visitors = app(AuthorRatings::class)->get((int) $user->ID);

        $bio = trim((string) $this->db->table('usermeta')
            ->where('user_id', $user->ID)->where('meta_key', 'description')->value('meta_value'));

        // array_merge, not +: "recipes" is the count in the card and the page of recipes here.
        return array_merge($this->card($user, $list['total'], $this->avatars([$user->ID])[$user->ID] ?? ''), [
            'bio' => $bio,
            'since' => (int) substr($user->user_registered, 0, 4),
            // Visitors' stars; until someone rates the author, the average of their recipes' ratings.
            'rating' => $visitors['average'] ?? ($ratings->isEmpty() ? null : round($ratings->avg(), 1)),
            'ratingCount' => $visitors['count'],
            'news' => app(News::class)->getByAuthor((int) $user->ID),
            'posts' => app(Blog::class)->getByAuthor((int) $user->ID),
            'seo' => app(Seo::class)->forUser($user->user_nicename),
        ], $list);
    }

    /** WordPress user id of an author with published recipes, or null. */
    public function getId(string $slug): ?int
    {
        $id = $this->db->table('users')->where('user_nicename', $slug)->value('ID');

        return $id && $this->recipesQuery()->where('p.post_author', $id)->exists() ? (int) $id : null;
    }

    /** Published Russian recipes, without a select. */
    private function recipesQuery(): Builder
    {
        return $this->db->table('posts as p')
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish');
    }

    /** user_id => avatar URL ("artit_user_avatar" is an attachment id). */
    private function avatars(array $userIds): array
    {
        $ids = $this->db->table('usermeta')
            ->whereIn('user_id', $userIds)
            ->where('meta_key', 'artit_user_avatar')
            ->pluck('meta_value', 'user_id')
            ->map(fn ($id) => (int) $id)
            ->filter();
        $urls = app(Attachments::class)->urls($ids->values()->all());

        return $ids->map(fn ($id) => $urls[$id] ?? '')->filter()->all();
    }

    private function card(object $user, int $recipes, string $avatar): array
    {
        return [
            'id' => (int) $user->ID,
            'slug' => $user->user_nicename,
            'name' => html_entity_decode($user->display_name, ENT_QUOTES | ENT_HTML5),
            'avatar' => $avatar,
            'recipes' => $recipes,
        ];
    }
}
