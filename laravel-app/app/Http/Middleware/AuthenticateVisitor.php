<?php

namespace App\Http\Middleware;

use App\Services\ApiTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Routes for logged-in visitors: "Authorization: Bearer <token>" → $request->attributes 'wp_user_id'. */
class AuthenticateVisitor
{
    public function __construct(private ApiTokens $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->bearerToken() ? $this->tokens->userId($request->bearerToken()) : null;
        if (! $userId) {
            return response()->json(['message' => 'Войдите в аккаунт'], 401);
        }
        $request->attributes->set('wp_user_id', $userId);

        return $next($request);
    }
}
