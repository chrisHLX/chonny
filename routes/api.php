<?php

use App\Http\Controllers\Api\CoachPagesController;
use App\Http\Controllers\Api\CoachUploadController;
use Illuminate\Support\Facades\Route;

// The desktop app uploading a player's games, with the key from /wow/coach (a Bearer token, so no
// session and no CSRF). See CoachUploadController.
Route::post('/coach/round', [CoachUploadController::class, 'round'])->name('api.coach.round');
Route::post('/coach/done', [CoachUploadController::class, 'done'])->name('api.coach.done');
Route::get('/coach/version', [CoachUploadController::class, 'version'])->name('api.coach.version');
// The app picking up its key once the player approves it in the browser (AppDownloadController).
Route::get('/coach/connect/{code}', [\App\Http\Controllers\AppDownloadController::class, 'pickup'])->middleware('throttle:120,1,app-connect-poll')->name('api.coach.connect');
// Which version of the app the website serves, so the app can update itself (AppDownloadController).
Route::get('/coach/app-version', [\App\Http\Controllers\AppDownloadController::class, 'appVersion'])->middleware('throttle:60,1,app-version')->name('api.coach.app-version');

// Notes, Ctrl+Shift+M marks, and the players in the game being played, from the app (CoachAppController).
Route::post('/coach/note', [\App\Http\Controllers\Api\CoachAppController::class, 'note'])->name('api.coach.note');
Route::post('/coach/players', [\App\Http\Controllers\Api\CoachAppController::class, 'players'])->name('api.coach.players');
// Uploading a game to the player's own YouTube channel (CoachYouTubeController, 2026-10-26).
Route::post('/coach/youtube/prepare', [\App\Http\Controllers\Api\CoachYouTubeController::class, 'prepare'])->middleware('throttle:30,1,app-youtube')->name('api.coach.youtube.prepare');
Route::post('/coach/youtube/uploaded', [\App\Http\Controllers\Api\CoachYouTubeController::class, 'uploaded'])->middleware('throttle:30,1,app-youtube')->name('api.coach.youtube.uploaded');
Route::get('/coach/youtube/videos', [\App\Http\Controllers\Api\CoachYouTubeController::class, 'videos'])->middleware('throttle:30,1,app-youtube-videos')->name('api.coach.youtube.videos');

// The desktop app reading the player's pages back, with the same key. See CoachPagesController.
Route::get('/coach/index', [CoachPagesController::class, 'index'])->name('api.coach.index');
Route::get('/coach/page/{file}', [CoachPagesController::class, 'page'])
    ->where('file', '[a-z0-9-]+\.html')->name('api.coach.page');
// One game's Watch page for the app's Videos tab: its timeline under the video the app recorded.
Route::get('/coach/watch/{lobby}', [CoachPagesController::class, 'watch'])
    ->where('lobby', '[0-9a-f]{32}')->middleware('throttle:60,1,app-watch')->name('api.coach.watch');
