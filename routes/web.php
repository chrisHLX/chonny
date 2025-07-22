<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Models\Unit;
use App\Models\Concept;
use App\Models\Question;
use App\Http\Controllers\ConceptController;
use App\Http\Controllers\QuestionController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

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
    $concepts = Concept::orderBy('type')->orderBy('name')->get();
    return view('concepts.index', compact('concepts'));
})->name('concepts.index');

Route::get('modules', function () {
    $modules = \App\Models\Module::with('users', 'questions')->get();
    return view('modules.index', compact('modules'));
})->name('modules.index');

Route::get('/concepts/create', [ConceptController::class, 'create'])->name('concepts.create');
Route::post('/concepts', [ConceptController::class, 'store'])->name('concepts.store');

//questions
Route::get('/questions', [QuestionController::class, 'index'])->name('questions.index');
Route::get('/questions/quiz', [QuestionController::class, 'quiz'])->name('questions.quiz.index');
Route::post('/quiz/submit-all', [QuestionController::class, 'submitAll'])->name('quiz.submitAll');
Route::post('/questions/{question}/answer', [QuestionController::class, 'submit'])->name('questions.answer');

//modules
use App\Http\Controllers\ModuleQuizController;
Route::middleware('auth')->group(function () {
    Route::get('/modules/{module}', [ModuleQuizController::class, 'show'])->name('modules.quiz');
    Route::post('/modules/{module}/start', [ModuleQuizController::class, 'start'])->name('modules.start');
});


// use this command to create a controller:
// php artisan make:controller QuestionController --model=Question

require __DIR__.'/auth.php';
