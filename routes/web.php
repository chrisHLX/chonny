<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Models\Unit;
use App\Models\Concept;
use App\Models\Question;
use App\Models\Module;
use App\Models\User;
use App\Http\Controllers\ConceptController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\UserProgressController;
use App\Http\Controllers\ModuleQuizController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\ReplayController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\ProficiencyController;
use App\Http\Controllers\CategoryController;

// STRIPE ROUTES
Route::post('/create-checkout-session', [StripeController::class, 'createCheckoutSession'])->name('checkout.session');
Route::post('/webhook/stripe', [StripeWebhookController::class, 'handle']);

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

Route::post('/credits', [AiController::class, 'test'])->name('credit.test');
Route::post('/creditsTest2', [AiController::class, 'test2'])->name('credit.test2');

Route::get('/dashboard/progress', [UserProgressController::class, 'index'])
    ->middleware(['auth'])
    ->name('dashboard.progress');


Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/units', function () {
    $units = Unit::with(['attributes', 'abilities', 'counters.counterUnit'])->orderBy('race')->orderBy('name')->get();
    return view('units.index', compact('units'));
})->name('units.index');

Route::get('/units/table', function () {
    $units = Unit::with('attributes')->orderBy('race')->orderBy('name')->get();
    return view('units.table', compact('units'));
})->name('units.table');

Route::get('/concepts', [ConceptController::class, 'index'])->name('concepts.index');

Route::get('/concepts/create', [ConceptController::class, 'create'])->name('concepts.create');
Route::post('/concepts', [ConceptController::class, 'store'])->name('concepts.store');

//questions
Route::get('/questions', [QuestionController::class, 'index'])->name('questions.index');
Route::delete('/questions/{question}', [QuestionController::class, 'destroy'])->name('questions.destroy')->middleware('auth');
Route::get('/questions/quiz', [QuestionController::class, 'quiz'])->name('questions.quiz.index')->middleware('auth');
Route::post('/questions/{question}/answer', [QuestionController::class, 'submit'])->name('questions.answer');
Route::post('/questions', [QuestionController::class, 'store'])->name('questions.store');

//get problematic questions
Route::get('/questions/problematic', [QuestionController::class, 'problematic'])->name('questions.problematic')->middleware('auth');

//create and store modules

Route::get('modules', [ModuleController::class, 'index'])->name('modules.index')->middleware('auth');

Route::get('/modules/create', [ModuleController::class, 'create'])->name('modules.create')->middleware('auth');
Route::post('/modules-pagex/createLandingPage/{module}', [ModuleController::class, 'createLandingPage'])->name('modules-pagex.createLandingPage')->middleware('auth');
Route::post('/modules', [ModuleController::class, 'store'])->name('modules.store')->middleware('auth');
Route::delete('/modules/{module}', [ModuleController::class, 'destroy'])->name('modules.destroy')->middleware('auth');
Route::get('/modules/{module}/edit', [ModuleController::class, 'edit'])->name('modules.edit')->middleware('auth');
Route::put('/modules/{module}', [ModuleController::class, 'update'])->name('modules.update')->middleware('auth');

// Module Suggestions
Route::get('/modules/next-module/{module}', [ModuleController::class, 'nextModule'])->name('modules.next-module')->middleware('auth');

//Routes can be very temperamental, so we need to create unique routes for each action for example
//Dont use the same route for both destroy and destroyPage, even if they are similar 
//Thats why we changed the destroyPage route to be more specific
//Module Pages
Route::delete('/module-page/{modulePage}', [ModuleController::class, 'destroyPage'])
    ->name('module-page.destroyPage')
    ->middleware('auth');


Route::middleware('auth')->group(function () {
    Route::get('/modules/{module}', [ModuleQuizController::class, 'show'])->name('modules.quiz');
    Route::post('/modules/{module}/start', [ModuleQuizController::class, 'start'])->name('modules.start');
});

Route::post('/modules/{module}/assign', [ModuleController::class, 'assign'])->name('modules.assign');
Route::post('/modules/create-suggested', [ModuleController::class, 'createSuggested'])->name('modules.create-suggested');

// Ai Requests Page
Route::get('/ai_requests', [AiController::class, 'index'])->name('ai_requests.index');
Route::post('/modules/{module}/generate-landing-page', [ModuleController::class, 'generateQuestions'])->name('modules.generateLandingPage');
Route::get('/modules/{module}/page', [ModuleController::class, 'page'])->name('modules.page');


// replay routes

Route::middleware(['auth'])->group(function () {
    Route::get('/replays/upload', [ReplayController::class, 'create'])->name('replays.create');
    Route::post('/replays/upload', [ReplayController::class, 'store'])->name('replays.store');

    Route::get('/replays/{replay}', [ReplayController::class, 'show'])->name('replays.show');
});

Route::get('/proficiencies/by-subject/{subject}', [ProficiencyController::class, 'bySubject']);

// Category routes
// routes/web.php


// use this command to create a controller:
// php artisan make:controller QuestionController --model=Question

require __DIR__.'/auth.php';
