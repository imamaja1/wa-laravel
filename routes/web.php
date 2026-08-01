<?php

use App\Http\Controllers\SendWhatsAppController;
use App\Http\Controllers\WaController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::get('/wa', [WaController::class, 'index'])->name('wa.index');
Route::get('/wa/status', [WaController::class, 'status'])->name('wa.status');

Route::post('/wa/send', SendWhatsAppController::class)
    ->middleware('throttle:10,1')
    ->name('wa.send');

Route::get('/wa/jobs/{job}', [SendWhatsAppController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('wa.jobs.show');
