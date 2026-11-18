<?php

use App\Http\Controllers\PullRequestController;
use App\Http\Controllers\WorkItemController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check() ? to_route('pull-requests.index') : Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => to_route('pull-requests.index'))->name('dashboard');

    Route::get('pull-requests', [PullRequestController::class, 'index'])->name('pull-requests.index');

    Route::get('work-items', [WorkItemController::class, 'index'])->name('work-items.index');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
