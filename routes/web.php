<?php

use App\Http\Controllers\AgentAvailabilityController;
use App\Http\Controllers\DialerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dialer', [DialerController::class, 'show'])->name('dialer');
Route::get('/agents', [AgentAvailabilityController::class, 'index'])->name('agents');
