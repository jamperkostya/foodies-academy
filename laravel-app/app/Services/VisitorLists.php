<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A logged-in visitor's favourite recipes, followed authors and visits, kept in their WordPress
 * usermeta: one "foodies_favorite" row per recipe id, one "foodies_follow_author" row per author id,
 * "foodies_last_visit" / "foodies_prev_visit" as GMT "Y-m-d H:i:s".
 */
class VisitorLists
{
    private const FAVORITE = 'foodies_favorite';
    private const FOLLOW = 'foodies_follow_author';
    private const LAST_VISIT = 'foodies_last_visit';
    private const PREV_VISIT = 'foodies_prev_visit';
    // Requests closer than this belong to one visit.
    private const VISIT_GAP_MINUTES = 30;

    private ConnectionInterface $db;

    public function __construct(private Recipes $recipes, private Authors $authors)
    {
        $this->db = DB::connection('site');
    }

    /** Recipe ids, last added first. */
    public function favoriteIds(int $userId): array
    {
        return $this->ids($userId, self::FAVORITE);
    }

    public function favoriteRecipes(int $userId): array
    {
        return $this->recipes->getByIds($this->favoriteIds($userId));
    }

    public function setFavorite(int $userId, int $recipeId, bool $on): void
    {
        $this->set($userId, self::FAVORITE, $recipeId, $on);
    }

    public function followedIds(int $userId): array
    {
        return $this->ids($userId, self::FOLLOW);
    }

    public function setFollow(int $userId, int $authorId, bool $on): void
    {
        $this->set($userId, self::FOLLOW, $authorId, $on);
    }

    /** Cards of the followed authors, last followed first. */
    public function followedAuthors(int $userId): array
    {
        $order = array_flip($this->followedIds($userId));

        return collect($this->authors->getAll())
            ->filter(fn ($author) => isset($order[$author['id']]))
            ->sortBy(fn ($author) => $order[$author['id']])
            ->values()
            ->all();
    }

    /**
     * Notes a request of the visitor. After a break longer than VISIT_GAP_MINUTES a new visit
     * starts and the previous one becomes the "since" of newRecipes().
     */
    public function touchVisit(int $userId): void
    {
        $now = Carbon::now('UTC');
        $last = $this->meta($userId, self::LAST_VISIT);
        if ($last && $now->copy()->subMinutes(self::VISIT_GAP_MINUTES)->lt(Carbon::parse($last, 'UTC'))) {
            $this->putMeta($userId, self::LAST_VISIT, $now->format('Y-m-d H:i:s'));

            return;
        }
        // New visit: the one before it is what "new since your last visit" counts from.
        $this->putMeta($userId, self::PREV_VISIT, $last ?: $now->format('Y-m-d H:i:s'));
        $this->putMeta($userId, self::LAST_VISIT, $now->format('Y-m-d H:i:s'));
    }

    /** Recipes of followed authors published since the previous visit: {since: ISO, recipes}. */
    public function newRecipes(int $userId, int $limit = 24): array
    {
        $since = $this->meta($userId, self::PREV_VISIT) ?? Carbon::now('UTC')->format('Y-m-d H:i:s');

        return [
            'since' => Carbon::parse($since, 'UTC')->toIso8601String(),
            'recipes' => $this->recipes->getByAuthorsSince($this->followedIds($userId), $since, $limit),
        ];
    }

    private function ids(int $userId, string $key): array
    {
        return $this->db->table('usermeta')
            ->where('user_id', $userId)
            ->where('meta_key', $key)
            ->orderByDesc('umeta_id')
            ->pluck('meta_value')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function set(int $userId, string $key, int $id, bool $on): void
    {
        $query = $this->db->table('usermeta')->where('user_id', $userId)->where('meta_key', $key)->where('meta_value', (string) $id);
        if (! $on) {
            $query->delete();
        } elseif (! $query->exists()) {
            $this->db->table('usermeta')->insert(['user_id' => $userId, 'meta_key' => $key, 'meta_value' => (string) $id]);
        }
    }

    private function meta(int $userId, string $key): ?string
    {
        return $this->db->table('usermeta')->where('user_id', $userId)->where('meta_key', $key)->value('meta_value');
    }

    private function putMeta(int $userId, string $key, string $value): void
    {
        $updated = $this->db->table('usermeta')->where('user_id', $userId)->where('meta_key', $key)->update(['meta_value' => $value]);
        if (! $updated && $this->meta($userId, $key) === null) {
            $this->db->table('usermeta')->insert(['user_id' => $userId, 'meta_key' => $key, 'meta_value' => $value]);
        }
    }
}
