<?php

use App\Http\Controllers\Api\RecipesController;
use App\Http\Controllers\Api\AuthorsController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\ContentChangedController;
use App\Http\Controllers\Api\CollectionsController;
use App\Http\Controllers\Api\IngredientsController;
use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\PagesController;
use App\Http\Controllers\Api\VideosController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\LifehacksController;
use Illuminate\Support\Facades\Route;

Route::prefix('recipes')->controller(RecipesController::class)->group(function () {
    Route::get('/top', 'top');
    Route::get('/new', 'new');
    Route::get('/slugs', 'slugs');
    Route::get('/featured', 'featured');
    Route::get('/by-ingredients', 'byIngredients');
    Route::get('/search-index', 'searchIndex');
    Route::get('/{slug}', 'show')->where('slug', '[a-z0-9_-]+');
    // Limits per minute: see AppServiceProvider.
    Route::post('/{slug}/comments', 'comment')->where('slug', '[a-z0-9_-]+')->middleware('throttle:comments');
});

// Likes (Posts Like Dislike), searches (Search Meter) and subscriptions (Contact Form 7 + CFDB7).
Route::controller(EngagementController::class)->group(function () {
    Route::get('/{type}/{slug}/likes', 'likes')->whereIn('type', ['recipes', 'videos'])->where('slug', '[a-z0-9_-]+');
    Route::post('/{type}/{slug}/likes', 'vote')->whereIn('type', ['recipes', 'videos'])->where('slug', '[a-z0-9_-]+')->middleware('throttle:likes');
    Route::post('/{type}/{slug}/view', 'view')->whereIn('type', ['recipes', 'videos'])->where('slug', '[a-z0-9_-]+')->middleware('throttle:views');
    Route::post('/search-log', 'search')->middleware('throttle:search-log');
    Route::post('/subscribe', 'subscribe')->middleware('throttle:subscribe');
    Route::get('/authors/{slug}/rating', 'authorRating')->where('slug', '[a-z0-9_-]+');
    Route::post('/authors/{slug}/rating', 'rateAuthor')->where('slug', '[a-z0-9_-]+')->middleware('throttle:ratings');
});

Route::prefix('categories')->controller(CategoriesController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/popular', 'popular');
    Route::get('/search', 'search');
    // After /popular and /search, so those aren't taken as slugs.
    Route::get('/{slug}', 'show')->where('slug', '[a-z0-9_-]+');
});

// News (post type "news") and the blog (ordinary posts): the same routes.
foreach (['news' => NewsController::class, 'blog' => BlogController::class] as $prefix => $controller) {
    Route::prefix($prefix)->controller($controller)->group(function () {
        Route::get('/', 'index');
        Route::get('/slugs', 'slugs');
        Route::get('/{slug}', 'show')->where('slug', '[a-z0-9_-]+');
        Route::post('/{slug}/comments', 'comment')->where('slug', '[a-z0-9_-]+')->middleware('throttle:comments');
    });
}

Route::prefix('collections')->controller(CollectionsController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/{slug}', 'show')->where('slug', '[a-z0-9_-]+');
});

Route::prefix('ingredients')->controller(IngredientsController::class)->group(function () {
    Route::get('/popular', 'popular');
    Route::get('/{slug}', 'show')->where('slug', '[a-z0-9_-]+');
});

Route::prefix('authors')->controller(AuthorsController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/{slug}', 'show')->where('slug', '[a-z0-9_-]+');
});

Route::get('/stats', [StatsController::class, 'show']);
Route::get('/pages', [PagesController::class, 'index']);
Route::get('/pages/{slug}', [PagesController::class, 'show'])->where('slug', '[a-z0-9_-]+');

Route::prefix('videos')->controller(VideosController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/slugs', 'slugs');
    Route::get('/{slug}', 'show')->where('slug', '[a-z0-9_-]+');
    Route::post('/{slug}/comments', 'comment')->where('slug', '[a-z0-9_-]+')->middleware('throttle:comments');
});

// Site accounts (WordPress users). Logged-in routes need "Authorization: Bearer <token>".
Route::controller(AccountController::class)->group(function () {
    Route::post('/auth/register', 'register')->middleware('throttle:register');
    Route::post('/auth/login', 'login')->middleware('throttle:login');
    Route::post('/auth/logout', 'logout');

    Route::middleware(['visitor', 'throttle:account'])->prefix('me')->group(function () {
        Route::get('/', 'me');
        Route::post('/', 'update');
        Route::get('/favorites', 'favorites');
        Route::put('/favorites/{slug}', 'addFavorite')->where('slug', '[a-z0-9_-]+');
        Route::delete('/favorites/{slug}', 'removeFavorite')->where('slug', '[a-z0-9_-]+');
        Route::get('/follows', 'follows');
        Route::put('/follows/{slug}', 'follow')->where('slug', '[a-z0-9_-]+');
        Route::delete('/follows/{slug}', 'unfollow')->where('slug', '[a-z0-9_-]+');
    });
});

Route::prefix('lifehacks')->controller(LifehacksController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/{slug}', 'show')->where('slug', '[a-z0-9_-]+');
    Route::post('/{slug}/comments', 'comment')->where('slug', '[a-z0-9_-]+')->middleware('throttle:comments');
});

// WordPress reports content changes: clear the cache, rebuild the static site (App\Services\SiteRebuild).
Route::post('/internal/content-changed', ContentChangedController::class)->middleware('throttle:120,1');
