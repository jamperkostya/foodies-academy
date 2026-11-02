<?php

use App\Http\Controllers\Api\RecipesController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\CollectionsController;
use App\Http\Controllers\Api\IngredientsController;
use Illuminate\Support\Facades\Route;

Route::prefix('recipes')->controller(RecipesController::class)->group(function () {
    Route::get('/top', 'top');
    Route::get('/new', 'new');
    Route::get('/slugs', 'slugs');
    Route::get('/featured', 'featured');
    Route::get('/by-ingredients', 'byIngredients');
    Route::get('/{slug}', 'show')->where('slug', '[a-z0-9-]+');
    // 5 comments a minute per visitor, against spam bursts.
    Route::post('/{slug}/comments', 'comment')->where('slug', '[a-z0-9-]+')->middleware('throttle:5,1');
});

Route::prefix('categories')->controller(CategoriesController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/popular', 'popular');
    Route::get('/search', 'search');
    // After /popular and /search, so those aren't taken as slugs.
    Route::get('/{slug}', 'show')->where('slug', '[a-z0-9-]+');
});

Route::prefix('news')->controller(NewsController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/slugs', 'slugs');
    Route::get('/{slug}', 'show')->where('slug', '[a-z0-9-]+');
});

Route::prefix('collections')->controller(CollectionsController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/{slug}', 'show')->where('slug', '[a-z0-9-]+');
});

Route::prefix('ingredients')->controller(IngredientsController::class)->group(function () {
    Route::get('/popular', 'popular');
    Route::get('/{slug}', 'show')->where('slug', '[a-z0-9-]+');
});
