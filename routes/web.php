<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Exit impersonation (accessible by impersonated users)
    Route::post('admin/exit-impersonation', [\App\Http\Controllers\Admin\CustomerController::class, 'exitImpersonation'])->name('admin.exit-impersonation');

    // Admin-only routes
    Route::middleware('admin')->group(function () {
        Route::resource('admin/customers', \App\Http\Controllers\Admin\CustomerController::class)->names('admin.customers');
        Route::post('admin/customers/{customer}/impersonate', [\App\Http\Controllers\Admin\CustomerController::class, 'impersonate'])->name('admin.customers.impersonate');
        Route::resource('admin/products', \App\Http\Controllers\Admin\ProductController::class)->names('admin.products');
        Route::resource('admin/invoices', \App\Http\Controllers\Admin\InvoiceController::class)->names('admin.invoices');
        Route::resource('admin/payments', \App\Http\Controllers\Admin\PaymentController::class)->names('admin.payments');
        Route::resource('admin/services', \App\Http\Controllers\Admin\ServiceController::class)->names('admin.services');
        Route::resource('admin/nodes', \App\Http\Controllers\Admin\NodeController::class)->names('admin.nodes');
        Route::post('admin/nodes/{node}/status', [\App\Http\Controllers\Admin\NodeController::class, 'updateStatus'])->name('admin.nodes.update-status');
        Route::post('admin/nodes/{node}/utilization', [\App\Http\Controllers\Admin\NodeController::class, 'updateUtilization'])->name('admin.nodes.update-utilization');
        Route::post('admin/nodes/{node}/heartbeat', [\App\Http\Controllers\Admin\NodeController::class, 'heartbeat'])->name('admin.nodes.heartbeat');
        Route::delete('admin/nodes/{node}', [\App\Http\Controllers\Admin\NodeController::class, 'delete'])->name('admin.nodes.delete');
        Route::resource('admin/domains', \App\Http\Controllers\Admin\DomainController::class)->names('admin.domains');
        Route::get('admin/domains-pricing', [\App\Http\Controllers\Admin\DomainController::class, 'pricing'])->name('admin.domains.pricing');
        Route::post('admin/domains-pricing', [\App\Http\Controllers\Admin\DomainController::class, 'storePricing'])->name('admin.domains.pricing.store');
        Route::post('admin/domain-extensions', [\App\Http\Controllers\Admin\DomainController::class, 'storeExtension'])->name('admin.domain-extensions.store');
        Route::resource('admin/orders', \App\Http\Controllers\Admin\OrderController::class)->only(['index', 'show'])->names('admin.orders');
        Route::resource('admin/resellers', \App\Http\Controllers\Admin\ResellerController::class)->only(['index', 'show'])->names('admin.resellers');
        Route::post('admin/resellers/{user}/promote', [\App\Http\Controllers\Admin\ResellerController::class, 'promote'])->name('admin.resellers.promote');
        Route::post('admin/resellers/{user}/demote', [\App\Http\Controllers\Admin\ResellerController::class, 'demote'])->name('admin.resellers.demote');
        Route::get('admin/settings', [\App\Http\Controllers\Admin\SettingController::class, 'index'])->name('admin.settings.index');
        Route::post('admin/settings', [\App\Http\Controllers\Admin\SettingController::class, 'update'])->name('admin.settings.update');

        // Service actions
        Route::post('admin/services/{service}/provision', [\App\Http\Controllers\Admin\ServiceController::class, 'provision'])->name('admin.services.provision');
        Route::post('admin/services/{service}/suspend', [\App\Http\Controllers\Admin\ServiceController::class, 'suspend'])->name('admin.services.suspend');
        Route::post('admin/services/{service}/unsuspend', [\App\Http\Controllers\Admin\ServiceController::class, 'unsuspend'])->name('admin.services.unsuspend');
        Route::post('admin/services/{service}/terminate', [\App\Http\Controllers\Admin\ServiceController::class, 'terminate'])->name('admin.services.terminate');
        Route::post('admin/services/{service}/refresh-status', [\App\Http\Controllers\Admin\ServiceController::class, 'refreshStatus'])->name('admin.services.refresh-status');

        // Placeholder routes for future implementation
        Route::get('/tickets', fn() => view('admin.tickets.index'))->name('tickets.index');
    });

    // Customer-only routes
    Route::middleware('customer')->group(function () {
        Route::get('/my/services', [\App\Http\Controllers\Customer\ServiceController::class, 'index'])->name('customer.services.index');
        Route::get('/my/services/{service}', [\App\Http\Controllers\Customer\ServiceController::class, 'show'])->name('customer.services.show');
        Route::resource('my/orders', \App\Http\Controllers\Customer\OrderController::class)->only(['index', 'show'])->names('customer.orders');
        Route::resource('my/invoices', \App\Http\Controllers\Customer\InvoiceController::class)->only(['index', 'show'])->names('customer.invoices');
        Route::resource('my/payments', \App\Http\Controllers\Customer\PaymentController::class)->only(['index', 'show'])->names('customer.payments');
        Route::get('/my/domains/available', fn() => view('customer.domains.available', ['extensions' => \App\Models\DomainExtension::where('enabled', true)->get()]))->name('customer.domains.available');
        Route::get('/my/tickets', fn() => view('customer.tickets.index'))->name('customer.tickets.index');
    });

    // Profile (accessible to all authenticated users)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
