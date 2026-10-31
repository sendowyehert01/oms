<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'hello')->name('home');

Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
