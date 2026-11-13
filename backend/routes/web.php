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

// IndexNow key for Findriva's own public pages (only when a platform key is configured).
Route::get('/{key}.txt', function (string $key) {
    abort_unless(filled(config('findriva.indexnow.platform_key')) && hash_equals((string) config('findriva.indexnow.platform_key'), $key), 404);

    return response($key, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
})->where('key', '[A-Za-z0-9-]{8,128}');
