<?php

use App\Http\Controllers\DialerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dialer', [DialerController::class, 'show'])->name('dialer');
