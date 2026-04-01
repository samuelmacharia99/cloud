<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Products
    Route::resource('products', ProductController::class);

    // Services
    Route::resource('services', ServiceController::class);

    // Invoices
    Route::resource('invoices', InvoiceController::class);

    // Tickets
    Route::resource('tickets', TicketController::class)->only(['index', 'show', 'create', 'store']);
    Route::post('/tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
    Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');

    // Admin only
    Route::middleware('admin')->group(function () {
        // Admin dashboard features
    });
});

require __DIR__.'/auth.php';
