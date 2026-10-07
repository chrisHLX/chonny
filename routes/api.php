<?php

use App\Http\Controllers\Api\CoachUploadController;
use Illuminate\Support\Facades\Route;

// The desktop app uploading a player's games, with the key from /wow/coach (a Bearer token, so no
// session and no CSRF). See CoachUploadController.
Route::post('/coach/round', [CoachUploadController::class, 'round'])->name('api.coach.round');
Route::post('/coach/done', [CoachUploadController::class, 'done'])->name('api.coach.done');
Route::get('/coach/version', [CoachUploadController::class, 'version'])->name('api.coach.version');
