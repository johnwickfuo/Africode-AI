<?php

use App\Http\Controllers\AccumulatorsController;
use App\Http\Controllers\AccuracyController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\MatchDetailController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('/match/{fixture}', MatchDetailController::class)->name('match.show');
Route::get('/accumulators', AccumulatorsController::class)->name('accumulators');
Route::get('/accuracy', AccuracyController::class)->name('accuracy');
Route::get('/history', HistoryController::class)->name('history');

// Session-bound (history + CSRF), hence in the web group despite the path.
Route::post('/api/chat', ChatController::class)->name('chat');
