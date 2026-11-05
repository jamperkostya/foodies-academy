<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiTokens;
use App\Services\Authors;
use App\Services\Recipes;
use App\Services\VisitorLists;
use App\Services\WordPressUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Site accounts: visitors are WordPress users; the site gets a token to send as "Authorization: Bearer". */
class AccountController extends Controller
{
    public function __construct(private WordPressUsers $users, private ApiTokens $tokens, private VisitorLists $lists) {}

    // POST /api/auth/register {name, email, password} → {token, user}
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:60',
            'email' => 'required|email|max:100',
            'password' => 'required|string|min:8|max:100',
        ]);
        $user = $this->users->register(strip_tags(trim($data['name'])), trim($data['email']), $data['password']);

        return response()->json(['data' => $this->session($user)], 201);
    }

    // POST /api/auth/login {email, password} → {token, user}
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|string|max:100', 'password' => 'required|string|max:100']);
        $user = $this->users->login(trim($data['email']), $data['password']);
        if (! $user) {
            return response()->json(['message' => 'Неверный e-mail или пароль'], 401);
        }

        return response()->json(['data' => $this->session($user)]);
    }

    // POST /api/auth/logout
    public function logout(Request $request): JsonResponse
    {
        $this->tokens->delete((string) $request->bearerToken());

        return response()->json(null, 204);
    }

    // GET /api/me → {user, favorites: [recipe ids], follows: [author ids]}. Also counts the visit.
    public function me(Request $request): JsonResponse
    {
        $id = $this->userId($request);
        $user = $this->users->get($id);
        abort_if(! $user, 401, 'Войдите в аккаунт');
        $this->lists->touchVisit($id);

        return response()->json(['data' => [
            'user' => $user,
            'favorites' => $this->lists->favoriteIds($id),
            'follows' => $this->lists->followedIds($id),
        ]]);
    }

    // POST /api/me {name?, firstName?, lastName?, description?, email?, password?, currentPassword?}
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|min:2|max:60',
            'firstName' => 'sometimes|nullable|string|max:60',
            'lastName' => 'sometimes|nullable|string|max:60',
            'description' => 'sometimes|nullable|string|max:1000',
            'email' => 'sometimes|required|email|max:100',
            'password' => 'sometimes|nullable|string|min:8|max:100',
            'currentPassword' => 'nullable|string|max:100',
        ]);
        $data = array_map(fn ($value) => is_string($value) ? trim($value) : ($value ?? ''), $data);

        return response()->json(['data' => $this->users->update($this->userId($request), $data)]);
    }

    // GET /api/me/favorites → recipe cards, last added first
    public function favorites(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->lists->favoriteRecipes($this->userId($request))]);
    }

    // PUT / DELETE /api/me/favorites/{slug}
    public function addFavorite(Request $request, string $slug, Recipes $recipes): JsonResponse
    {
        return $this->toggleFavorite($request, $slug, $recipes, true);
    }

    public function removeFavorite(Request $request, string $slug, Recipes $recipes): JsonResponse
    {
        return $this->toggleFavorite($request, $slug, $recipes, false);
    }

    // GET /api/me/follows → {authors, newRecipes: {since, recipes}}
    public function follows(Request $request): JsonResponse
    {
        $id = $this->userId($request);

        return response()->json(['data' => [
            'authors' => $this->lists->followedAuthors($id),
            'newRecipes' => $this->lists->newRecipes($id),
        ]]);
    }

    // PUT / DELETE /api/me/follows/{authorSlug}
    public function follow(Request $request, string $slug, Authors $authors): JsonResponse
    {
        return $this->toggleFollow($request, $slug, $authors, true);
    }

    public function unfollow(Request $request, string $slug, Authors $authors): JsonResponse
    {
        return $this->toggleFollow($request, $slug, $authors, false);
    }

    private function toggleFavorite(Request $request, string $slug, Recipes $recipes, bool $on): JsonResponse
    {
        $recipeId = $recipes->getRecipeId($slug);
        abort_if(! $recipeId, 404, 'Рецепт не найден');
        $this->lists->setFavorite($this->userId($request), $recipeId, $on);

        return response()->json(['data' => ['favorites' => $this->lists->favoriteIds($this->userId($request))]]);
    }

    private function toggleFollow(Request $request, string $slug, Authors $authors, bool $on): JsonResponse
    {
        $authorId = $authors->getId($slug);
        abort_if(! $authorId, 404, 'Автор не найден');
        $this->lists->setFollow($this->userId($request), $authorId, $on);

        return response()->json(['data' => ['follows' => $this->lists->followedIds($this->userId($request))]]);
    }

    private function session(array $user): array
    {
        return ['token' => $this->tokens->create((int) $user['id']), 'user' => $user];
    }

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('wp_user_id');
    }
}
