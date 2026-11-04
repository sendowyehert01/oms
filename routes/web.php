<?php

use App\Http\Controllers\BalanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderCreationController;
use App\Http\Controllers\OrderItemProductionController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentDueDateController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::redirect('/login', '/')->name('home');

Route::middleware('auth')->group(function (): void {
    Route::view('/account/password', 'auth.password')->name('account.password');
    Route::get('/staff', [StaffController::class, 'index'])->middleware('can:manage-staff')->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->middleware('can:manage-staff')->name('staff.store');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/create', [OrderCreationController::class, 'create'])->name('orders.create');
    Route::get('/orders/create/customer/{type}', [OrderCreationController::class, 'customer'])->whereIn('type', ['existing', 'new'])->name('orders.creation.customer');
    Route::post('/orders/create/customer', [OrderCreationController::class, 'selectCustomer'])->name('orders.creation.select-customer');
    Route::get('/orders/create/details', [OrderCreationController::class, 'details'])->name('orders.creation.details');
    Route::post('/orders/create/back', [OrderCreationController::class, 'back'])->name('orders.creation.back');
    Route::post('/orders/create/cancel', [OrderCreationController::class, 'cancel'])->name('orders.creation.cancel');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/balances', BalanceController::class)->name('balances.index');
    Route::post('/orders/{order}/payment-due-date', PaymentDueDateController::class)->whereNumber('order')->name('orders.payment-due-date.update');
    Route::post('/orders/{order}/status', OrderStatusController::class)->whereNumber('order')->name('orders.status.update');
    Route::post('/orders/{order}/items/{item}/production', OrderItemProductionController::class)->scopeBindings()->whereNumber(['order', 'item'])->name('orders.items.production.update');
    Route::get('/orders/{order}/payments/create', [PaymentController::class, 'create'])->whereNumber('order')->name('orders.payments.create');
    Route::post('/orders/{order}/payments', [PaymentController::class, 'store'])->whereNumber('order')->name('orders.payments.store');
    Route::get('/orders/{order}/payments/{payment}/receipt', [PaymentReceiptController::class, 'show'])->whereNumber(['order', 'payment'])->name('orders.payments.receipt');
    Route::get('/orders/samples/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.samples.show');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    Route::delete('/orders/samples/{order}', [OrderController::class, 'destroy'])->whereNumber('order')->name('orders.samples.destroy');
    Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->whereNumber('order')->name('orders.destroy');
});
