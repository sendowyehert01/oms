<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'hello')->name('home');

Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
Route::get('/orders/samples/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.samples.show');
Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
