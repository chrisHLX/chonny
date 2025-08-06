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

Route::get('/', function () {
    return view('welcome');
});

// Dashboard
Route::get('/dashboard', function () {
    $user = auth()->user();
    $modules = $user->modules()->get();
    $createdModules = Module::where('created_by', $user->id)->get();
    return view('dashboard', compact('user', 'modules', 'createdModules'));
})->middleware(['auth', 'verified'])->name('dashboard');

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

Route::get('/concepts', function () {
    $concepts = Concept::orderBy('name')->get();
    return view('concepts.index', compact('concepts'));
})->name('concepts.index');

Route::get('/concepts/create', [ConceptController::class, 'create'])->name('concepts.create');
Route::post('/concepts', [ConceptController::class, 'store'])->name('concepts.store');

//questions
Route::get('/questions', [QuestionController::class, 'index'])->name('questions.index');
Route::get('/questions/quiz', [QuestionController::class, 'quiz'])->name('questions.quiz.index')->middleware('auth');
Route::post('/quiz/submit-all', [QuestionController::class, 'submitAll'])->name('quiz.submitAll');
Route::post('/questions/{question}/answer', [QuestionController::class, 'submit'])->name('questions.answer');
Route::post('/questions', [QuestionController::class, 'store'])->name('questions.store');

//create and store modules

Route::get('modules', function () {
    $modules = Module::with('users', 'questions')->get();
    return view('modules.index', compact('modules'));
})->name('modules.index');

Route::get('/modules/create', [ModuleController::class, 'create'])->name('modules.create')->middleware('auth');
Route::post('/modules', [ModuleController::class, 'store'])->name('modules.store')->middleware('auth');
Route::delete('/modules/{module}', [ModuleController::class, 'destroy'])->name('modules.destroy')->middleware('auth');
Route::get('/modules/{module}/edit', [ModuleController::class, 'edit'])->name('modules.edit')->middleware('auth');
Route::put('/modules/{module}', [ModuleController::class, 'update'])->name('modules.update')->middleware('auth');



Route::middleware('auth')->group(function () {
    Route::get('/modules/{module}', [ModuleQuizController::class, 'show'])->name('modules.quiz');
    Route::post('/modules/{module}/start', [ModuleQuizController::class, 'start'])->name('modules.start');
});

Route::post('/modules/{module}/assign', [ModuleController::class, 'assign'])->name('modules.assign');

// Ai Requests Page
Route::get('/ai_requests', [AiController::class, 'index'])->name('ai_requests.index');


// use this command to create a controller:
// php artisan make:controller QuestionController --model=Question

require __DIR__.'/auth.php';
