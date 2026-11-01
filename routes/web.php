<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ProfileController::class, 'index'])->name('profiles.index');
Route::post('/profiles', [ProfileController::class, 'store'])->name('profiles.store');
Route::get('/profiles/{profile}', [ProfileController::class, 'show'])->name('profiles.show');
Route::post('/profiles/{profile}/refresh', [ProfileController::class, 'refresh'])->name('profiles.refresh');
