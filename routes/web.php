<?php

use App\Models\League;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Dashboard', [
        'leagues' => League::query()
            ->withCount('teams')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'country']),
    ]);
})->name('dashboard');
