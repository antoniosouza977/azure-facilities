<?php

use App\Http\Controllers\Settings\AzureTokenController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    // Gerenciar PATs exige ter confirmado a senha recentemente.
    Route::get('settings/azure-tokens', [AzureTokenController::class, 'index'])
        ->middleware('password.confirm')
        ->name('azure-tokens.index');

    Route::middleware('throttle:20,1')->group(function () {
        Route::post('settings/azure-tokens', [AzureTokenController::class, 'store'])->name('azure-tokens.store');
        Route::post('settings/azure-tokens/{token}/check', [AzureTokenController::class, 'check'])->name('azure-tokens.check');
        Route::patch('settings/azure-tokens/{token}/default', [AzureTokenController::class, 'makeDefault'])->name('azure-tokens.default');
        Route::delete('settings/azure-tokens/{token}', [AzureTokenController::class, 'destroy'])->name('azure-tokens.destroy');
    });

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');
});
