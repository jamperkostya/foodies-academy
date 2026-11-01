<?php

use App\Http\Controllers\Api\RecipesController;
use Illuminate\Support\Facades\Route;

Route::prefix('recipes')->controller(RecipesController::class)->group(function () {
    Route::get('/top', 'top');
    Route::get('/new', 'new');
    Route::get('/category/{id}', 'byCategory')->whereNumber('id');
});
