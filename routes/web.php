<?php

use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderCreationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'hello')->name('home');

Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
Route::get('/orders/create', [OrderCreationController::class, 'create'])->name('orders.create');
Route::get('/orders/create/customer/{type}', [OrderCreationController::class, 'customer'])->whereIn('type', ['existing', 'new'])->name('orders.creation.customer');
Route::post('/orders/create/customer', [OrderCreationController::class, 'selectCustomer'])->name('orders.creation.select-customer');
Route::get('/orders/create/details', [OrderCreationController::class, 'details'])->name('orders.creation.details');
Route::post('/orders/create/back', [OrderCreationController::class, 'back'])->name('orders.creation.back');
Route::post('/orders/create/cancel', [OrderCreationController::class, 'cancel'])->name('orders.creation.cancel');
Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
Route::get('/orders/samples/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.samples.show');
Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
Route::delete('/orders/samples/{order}', [OrderController::class, 'destroy'])->whereNumber('order')->name('orders.samples.destroy');
Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->whereNumber('order')->name('orders.destroy');
