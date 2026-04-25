<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Models\Unit;
use App\Models\Concept;
use App\Models\Question;
use App\Models\Module;
use App\Models\User;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ConceptController;
use App\Http\Controllers\QuestionController;

use App\Http\Controllers\ModuleQuizController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\ReplayController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\ProficiencyController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\PipelineController;

use App\Livewire\Modules\Index;
use App\Livewire\QuizPage;
use App\Livewire\ModuleSelector;

// STRIPE
Route::post('/checkout/session', [StripeController::class, 'create'])
    ->middleware('auth')
    ->name('checkout.session');

Route::post('/webhook/stripe', [StripeWebhookController::class, 'handle']);

// Optional redirect pages
Route::get('/checkout/success', fn () => view('checkout.success'))->name('checkout.success');
Route::get('/checkout/cancel', fn () => view('checkout.cancel'))->name('checkout.cancel');


Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

Route::post('/credits', [AiController::class, 'test'])->name('credit.test');
Route::post('/creditsTest2', [AiController::class, 'test2'])->name('credit.test2');

// routes/web.php or api.php
Route::get('/pipelines/{pipeline}', [PipelineController::class, 'status']);

Route::get('/next-module/{pipeline}', [PipelineController::class, 'nextModule'])
    ->name('pipelines.next-module');


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
Route::get('/questions/quiz', QuizPage::class)->name('questions.quiz.index')->middleware('auth');
//Route::get('/questions', [QuestionController::class, 'index'])->name('questions.index');
Route::delete('/questions/{question}', [QuestionController::class, 'destroy'])->name('questions.destroy')->middleware('auth');
//Route::get('/questions/quiz', [QuestionController::class, 'quiz'])->name('questions.quiz.index')->middleware('auth');
Route::post('/questions/{question}/answer', [QuestionController::class, 'submit'])->name('questions.answer');
Route::post('/questions', [QuestionController::class, 'store'])->name('questions.store');

//get problematic questions
Route::get('/questions/problematic', [QuestionController::class, 'problematic'])->name('questions.problematic')->middleware('auth');

//create and store modules
Route::get('modules', Index::class)->name('modules.index')->middleware('auth');
//Route::get('modules', [ModuleController::class, 'index'])->name('modules.index')->middleware('auth');

Route::get('/modules/manage', [ModuleController::class, 'manage'])->name('modules.manage')->middleware('auth');
Route::get('/modules/create', [ModuleController::class, 'create'])->name('modules.create')->middleware('auth');
Route::post('/modules-pagex/createLandingPage/{module}', [ModuleController::class, 'createLandingPage'])->name('modules-pagex.createLandingPage')->middleware('auth');
Route::post('/modules/{module}/generate-questions', [ModuleController::class, 'generateQuestions'])
    ->name('modules.generate-questions');
Route::post('/modules', [ModuleController::class, 'store'])->name('modules.store')->middleware('auth');
Route::delete('/modules/destroy/{module}', [ModuleController::class, 'destroy'])->name('modules.destroy')->middleware('auth');
Route::get('/modules/{module}/edit', [ModuleController::class, 'edit'])->name('modules.edit')->middleware('auth');
Route::put('/modules/{module}', [ModuleController::class, 'update'])->name('modules.update')->middleware('auth');

// Module Suggestions
Route::get('/modules/next-module/{moduleId}', [ModuleController::class, 'nextModule'])->name('modules.next-module')->middleware('auth');

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
Route::post('/modules/{module}/generate-landing-page', [ModuleController::class, 'generateLandingPage'])->name('modules.generateLandingPage');
Route::get('/modules/{module}/page', [ModuleController::class, 'page'])->name('modules.page');

Route::get('/proficiencies/by-subject/{subject}', [ProficiencyController::class, 'bySubject']);
Route::get('/subjects/by-category/{category}', [CategoryController::class, 'subjectsByCategory']);

// Category routes
// routes/web.php


// use this command to create a controller:
// php artisan make:controller QuestionController --model=Question

// Collection routes
Route::get('/collection', [CollectionController::class, 'index'])->name('collection.index')->middleware('auth');

// ------- Backend UI Dashboard ------- CURRENTLY DISABLED //
Route::get('/admindash', [AdminController::class, 'index'])->name('admindash'); // Displays all tables and relationships.

// ------- Admin Content Management -------
Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Categories
    Route::get('/categories', [ContentController::class, 'categories'])->name('categories.index');
    Route::post('/categories', [ContentController::class, 'storeCategory'])->name('categories.store');
    Route::put('/categories/{category}', [ContentController::class, 'updateCategory'])->name('categories.update');
    Route::delete('/categories/{category}', [ContentController::class, 'destroyCategory'])->name('categories.destroy');

    // Subjects
    Route::get('/subjects', [ContentController::class, 'subjects'])->name('subjects.index');
    Route::post('/subjects', [ContentController::class, 'storeSubject'])->name('subjects.store');
    Route::put('/subjects/{subject}', [ContentController::class, 'updateSubject'])->name('subjects.update');
    Route::delete('/subjects/{subject}', [ContentController::class, 'destroySubject'])->name('subjects.destroy');

    // Proficiencies (managed within subjects page)
    Route::post('/proficiencies', [ContentController::class, 'storeProficiency'])->name('proficiencies.store');
    Route::put('/proficiencies/{proficiency}', [ContentController::class, 'updateProficiency'])->name('proficiencies.update');
    Route::delete('/proficiencies/{proficiency}', [ContentController::class, 'destroyProficiency'])->name('proficiencies.destroy');

    // Concepts
    Route::get('/concepts', [ContentController::class, 'concepts'])->name('concepts.index');
    Route::post('/concepts', [ContentController::class, 'storeConcept'])->name('concepts.store');
    Route::put('/concepts/{concept}', [ContentController::class, 'updateConcept'])->name('concepts.update');
    Route::delete('/concepts/{concept}', [ContentController::class, 'destroyConcept'])->name('concepts.destroy');
});



require __DIR__.'/auth.php';
