<?php

use App\Http\Controllers\PublicBusinessController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Published business pages (one-page websites) and their discovery files.
Route::get('/b/{slug}', [PublicBusinessController::class, 'show'])->where('slug', '[a-z0-9-]{3,140}')->middleware('throttle:public');
Route::get('/sitemap.xml', [PublicBusinessController::class, 'sitemap']);
Route::get('/robots.txt', [PublicBusinessController::class, 'robots']);
