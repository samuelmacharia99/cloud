<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware(['auth'])->group(function () {
    // Exit impersonation (accessible by all authenticated users, including impersonated customers)
    Route::post('admin/exit-impersonation', [\App\Http\Controllers\Admin\CustomerController::class, 'exitImpersonation'])->name('admin.exit-impersonation');
});

// Public domain search and checkout (no authentication required)
Route::get('/search-domains', [\App\Http\Controllers\Customer\DomainSearchController::class, 'search'])->name('domains.search.public');
Route::post('/sync-cart', [\App\Http\Controllers\Customer\CheckoutController::class, 'syncCart'])->name('checkout.sync-cart');
Route::get('/domain-checkout', [\App\Http\Controllers\Customer\CheckoutController::class, 'showPublic'])->name('checkout.show.public');
Route::post('/domain-checkout', [\App\Http\Controllers\Customer\CheckoutController::class, 'processPublic'])->name('checkout.process.public');

// Payment webhooks (public, no authentication required)
Route::post('/webhooks/mpesa/callback', [\App\Http\Controllers\Customer\PaymentController::class, 'mpesaCallback'])->name('payment.mpesa.callback');
Route::post('/webhooks/stripe', [\App\Http\Controllers\Customer\PaymentController::class, 'stripeWebhook'])->name('payment.stripe.webhook');
Route::post('/webhooks/paypal', [\App\Http\Controllers\Customer\PaymentController::class, 'paypalWebhook'])->name('payment.paypal.webhook');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Admin-only routes
    Route::middleware('admin')->group(function () {
        Route::resource('admin/customers', \App\Http\Controllers\Admin\CustomerController::class)->names('admin.customers');
        Route::post('admin/customers/{customer}/impersonate', [\App\Http\Controllers\Admin\CustomerController::class, 'impersonate'])->name('admin.customers.impersonate');
        Route::resource('admin/products', \App\Http\Controllers\Admin\ProductController::class)->names('admin.products');
        Route::resource('admin/invoices', \App\Http\Controllers\Admin\InvoiceController::class)->names('admin.invoices');
        Route::get('admin/invoices/{invoice}/download', [\App\Http\Controllers\Admin\InvoiceController::class, 'download'])->name('admin.invoices.download');
        Route::resource('admin/payments', \App\Http\Controllers\Admin\PaymentController::class)->names('admin.payments');
        Route::resource('admin/services', \App\Http\Controllers\Admin\ServiceController::class)->names('admin.services');
        Route::get('admin/services/create', [\App\Http\Controllers\Admin\ServiceController::class, 'create'])->name('admin.services.create');
        Route::post('admin/services', [\App\Http\Controllers\Admin\ServiceController::class, 'store'])->name('admin.services.store');
        Route::resource('admin/nodes', \App\Http\Controllers\Admin\NodeController::class)->names('admin.nodes');
        Route::post('admin/nodes/{node}/status', [\App\Http\Controllers\Admin\NodeController::class, 'updateStatus'])->name('admin.nodes.update-status');
        Route::post('admin/nodes/{node}/utilization', [\App\Http\Controllers\Admin\NodeController::class, 'updateUtilization'])->name('admin.nodes.update-utilization');
        Route::post('admin/nodes/{node}/heartbeat', [\App\Http\Controllers\Admin\NodeController::class, 'heartbeat'])->name('admin.nodes.heartbeat');
        Route::delete('admin/nodes/{node}', [\App\Http\Controllers\Admin\NodeController::class, 'delete'])->name('admin.nodes.delete');
        Route::resource('admin/domains', \App\Http\Controllers\Admin\DomainController::class)->names('admin.domains');
        Route::get('admin/domains-pricing', [\App\Http\Controllers\Admin\DomainController::class, 'pricing'])->name('admin.domains.pricing');
        Route::post('admin/domains-pricing', [\App\Http\Controllers\Admin\DomainController::class, 'storePricing'])->name('admin.domains.pricing.store');
        Route::post('admin/domain-extensions', [\App\Http\Controllers\Admin\DomainController::class, 'storeExtension'])->name('admin.domain-extensions.store');
        Route::resource('admin/reseller-packages', \App\Http\Controllers\Admin\ResellerPackageController::class)->names('admin.reseller-packages');
        Route::resource('admin/orders', \App\Http\Controllers\Admin\OrderController::class)->only(['index', 'show'])->names('admin.orders');
        Route::get('admin/resellers', [\App\Http\Controllers\Admin\ResellerController::class, 'index'])->name('admin.resellers.index');
        Route::get('admin/resellers/create', [\App\Http\Controllers\Admin\ResellerController::class, 'create'])->name('admin.resellers.create');
        Route::post('admin/resellers', [\App\Http\Controllers\Admin\ResellerController::class, 'store'])->name('admin.resellers.store');
        Route::get('admin/resellers/{user}', [\App\Http\Controllers\Admin\ResellerController::class, 'show'])->name('admin.resellers.show');
        Route::post('admin/resellers/{user}/promote', [\App\Http\Controllers\Admin\ResellerController::class, 'promote'])->name('admin.resellers.promote');
        Route::post('admin/resellers/{user}/demote', [\App\Http\Controllers\Admin\ResellerController::class, 'demote'])->name('admin.resellers.demote');
        Route::post('admin/resellers/{user}/assign-package', [\App\Http\Controllers\Admin\ResellerController::class, 'assignPackage'])->name('admin.resellers.assign-package');
        Route::post('admin/resellers/{user}/impersonate', [\App\Http\Controllers\Admin\ResellerController::class, 'impersonate'])->name('admin.resellers.impersonate');
        Route::get('admin/settings', [\App\Http\Controllers\Admin\SettingController::class, 'index'])->name('admin.settings.index');
        Route::post('admin/settings', [\App\Http\Controllers\Admin\SettingController::class, 'update'])->name('admin.settings.update');
        Route::post('admin/settings/upload-file', [\App\Http\Controllers\Admin\SettingController::class, 'uploadFile'])->name('admin.settings.upload-file');
        Route::post('admin/settings/test-smtp', [\App\Http\Controllers\Admin\SettingController::class, 'testSmtp'])->name('admin.settings.test-smtp');
        Route::post('admin/settings/test-sms', [\App\Http\Controllers\Admin\SettingController::class, 'testSms'])->name('admin.settings.test-sms');

        // Currency Management
        Route::get('admin/currencies', [\App\Http\Controllers\Admin\CurrencyController::class, 'index'])->name('admin.currencies.index');
        Route::post('admin/currencies', [\App\Http\Controllers\Admin\CurrencyController::class, 'store'])->name('admin.currencies.store');
        Route::patch('admin/currencies/{currency}', [\App\Http\Controllers\Admin\CurrencyController::class, 'update'])->name('admin.currencies.update');
        Route::delete('admin/currencies/{currency}', [\App\Http\Controllers\Admin\CurrencyController::class, 'destroy'])->name('admin.currencies.destroy');
        Route::post('admin/currencies/refresh', [\App\Http\Controllers\Admin\CurrencyController::class, 'refreshRates'])->name('admin.currencies.refresh');
        Route::post('admin/currencies/test-conversion', [\App\Http\Controllers\Admin\CurrencyController::class, 'testConversion'])->name('admin.currencies.test-conversion');

        // SMS Notifications
        Route::get('admin/sms', [\App\Http\Controllers\Admin\SmsController::class, 'index'])->name('admin.sms.index');
        Route::post('admin/sms/send', [\App\Http\Controllers\Admin\SmsController::class, 'send'])->name('admin.sms.send');

        // Email Logs
        Route::get('admin/emails', [\App\Http\Controllers\Admin\EmailController::class, 'index'])->name('admin.emails.index');
        Route::get('admin/emails/{email}', [\App\Http\Controllers\Admin\EmailController::class, 'show'])->name('admin.emails.show');

        // Cron Jobs
        Route::get('admin/cron', [\App\Http\Controllers\Admin\CronController::class, 'index'])->name('admin.cron.index');
        Route::get('admin/cron/{job}', [\App\Http\Controllers\Admin\CronController::class, 'show'])->name('admin.cron.show');
        Route::post('admin/cron/{job}/run', [\App\Http\Controllers\Admin\CronController::class, 'run'])->name('admin.cron.run');
        Route::post('admin/cron/{job}/toggle', [\App\Http\Controllers\Admin\CronController::class, 'toggle'])->name('admin.cron.toggle');
        Route::get('admin/cron/{job}/logs', [\App\Http\Controllers\Admin\CronController::class, 'logs'])->name('admin.cron.logs');

        // Service actions
        Route::post('admin/services/{service}/provision', [\App\Http\Controllers\Admin\ServiceController::class, 'provision'])->name('admin.services.provision');
        Route::post('admin/services/{service}/suspend', [\App\Http\Controllers\Admin\ServiceController::class, 'suspend'])->name('admin.services.suspend');
        Route::post('admin/services/{service}/unsuspend', [\App\Http\Controllers\Admin\ServiceController::class, 'unsuspend'])->name('admin.services.unsuspend');
        Route::post('admin/services/{service}/terminate', [\App\Http\Controllers\Admin\ServiceController::class, 'terminate'])->name('admin.services.terminate');
        Route::post('admin/services/{service}/refresh-status', [\App\Http\Controllers\Admin\ServiceController::class, 'refreshStatus'])->name('admin.services.refresh-status');

        // Container management
        Route::resource('admin/container-templates', \App\Http\Controllers\Admin\ContainerTemplateController::class)->names('admin.container-templates');
        Route::post('admin/services/{service}/container/restart', [\App\Http\Controllers\Admin\ContainerController::class, 'restart'])->name('admin.services.container.restart');
        Route::post('admin/services/{service}/container/stop', [\App\Http\Controllers\Admin\ContainerController::class, 'stop'])->name('admin.services.container.stop');
        Route::post('admin/services/{service}/container/start', [\App\Http\Controllers\Admin\ContainerController::class, 'start'])->name('admin.services.container.start');
        Route::get('admin/services/{service}/container/logs', [\App\Http\Controllers\Admin\ContainerController::class, 'logs'])->name('admin.services.container.logs');
        Route::get('admin/services/{service}/container/metrics', [\App\Http\Controllers\Admin\ContainerController::class, 'metrics'])->name('admin.services.container.metrics');
        Route::post('admin/services/{service}/container/redeploy', [\App\Http\Controllers\Admin\ContainerController::class, 'redeploy'])->name('admin.services.container.redeploy');
        Route::post('admin/services/{service}/container/domains', [\App\Http\Controllers\Admin\ContainerController::class, 'bindDomain'])->name('admin.services.container.domains.bind');
        Route::delete('admin/services/{service}/container/domains/{domain}', [\App\Http\Controllers\Admin\ContainerController::class, 'unbindDomain'])->name('admin.services.container.domains.unbind');
        Route::post('admin/services/{service}/container/domains/{domain}/ssl', [\App\Http\Controllers\Admin\ContainerController::class, 'enableSsl'])->name('admin.services.container.domains.ssl');

        // Container migration
        Route::get('admin/services/{service}/container/migrate', [\App\Http\Controllers\Admin\ContainerMigrationController::class, 'index'])->name('admin.services.container.migrate');
        Route::post('admin/services/{service}/container/migrate', [\App\Http\Controllers\Admin\ContainerMigrationController::class, 'migrate'])->name('admin.services.container.migrate.confirm');
        Route::post('admin/nodes/{node}/migrate-containers', [\App\Http\Controllers\Admin\ContainerMigrationController::class, 'migrateNode'])->name('admin.nodes.migrate-containers');

        // Placeholder routes for future implementation
        Route::get('/tickets', fn() => view('admin.tickets.index'))->name('tickets.index');
    });

    // Customer-only routes
    Route::middleware('customer')->group(function () {
        Route::get('/my/services', [\App\Http\Controllers\Customer\ServiceController::class, 'index'])->name('customer.services.index');
        Route::get('/my/services/{service}', [\App\Http\Controllers\Customer\ServiceController::class, 'show'])->name('customer.services.show');
        Route::post('/my/services/{service}/cancel', [\App\Http\Controllers\Customer\ServiceController::class, 'cancel'])->name('customer.services.cancel');
        Route::post('/my/services/{service}/renew', [\App\Http\Controllers\Customer\ServiceController::class, 'renew'])->name('customer.services.renew');
        Route::resource('my/orders', \App\Http\Controllers\Customer\OrderController::class)->only(['index', 'show'])->names('customer.orders');
        Route::resource('my/invoices', \App\Http\Controllers\Customer\InvoiceController::class)->only(['index', 'show'])->names('customer.invoices');
        Route::get('my/invoices/{invoice}/download', [\App\Http\Controllers\Customer\InvoiceController::class, 'download'])->name('customer.invoices.download');
        Route::resource('my/payments', \App\Http\Controllers\Customer\PaymentController::class)->only(['index', 'show'])->names('customer.payments');

        // Reseller package management (no enforcement on these routes)
        Route::get('my/packages', [\App\Http\Controllers\Reseller\PackageController::class, 'index'])->name('reseller.packages.index');
        Route::post('my/packages/{package}/subscribe', [\App\Http\Controllers\Reseller\PackageController::class, 'subscribe'])->name('reseller.packages.subscribe');

        // Shopping experience
        Route::get('/select-techstack', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'selectTechstack'])->name('customer.select-techstack');
        Route::post('/confirm-techstack', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'confirmTechstack'])->name('customer.confirm-techstack');
        Route::get('/api/languages/{language}/databases', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'getAvailableDatabases'])->name('api.languages.databases');
        Route::get('/api/databases/{database}/languages', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'getAvailableLanguages'])->name('api.databases.languages');
        Route::get('/api/products', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'getAvailableProducts'])->name('api.products');
        Route::get('/deploy-service', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'index'])->name('customer.deploy-service');
        Route::get('/browse-services', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'browse'])->name('customer.browse-services');
        Route::get('/my/domains', [\App\Http\Controllers\Customer\DomainController::class, 'index'])->name('customer.domains.index');
        Route::get('/domains/search', [\App\Http\Controllers\Customer\DomainSearchController::class, 'search'])->name('domains.search');

        // Shopping cart
        Route::get('/cart', [\App\Http\Controllers\Customer\CartController::class, 'index'])->name('customer.cart.index');
        Route::post('/cart/add', [\App\Http\Controllers\Customer\CartController::class, 'add'])->name('customer.cart.add');
        Route::delete('/cart/{key}', [\App\Http\Controllers\Customer\CartController::class, 'remove'])->name('customer.cart.remove');
        Route::post('/cart/clear', [\App\Http\Controllers\Customer\CartController::class, 'clear'])->name('customer.cart.clear');
        Route::post('/cart/check-domain', [\App\Http\Controllers\Customer\CartController::class, 'checkDomainAvailability'])->name('customer.cart.check-domain');

        // Checkout
        Route::get('/checkout', [\App\Http\Controllers\Customer\CheckoutController::class, 'show'])->name('customer.checkout.show');
        Route::post('/checkout', [\App\Http\Controllers\Customer\CheckoutController::class, 'process'])->name('customer.checkout.process');

        // Payment methods
        Route::get('/payments', [\App\Http\Controllers\Customer\PaymentController::class, 'index'])->name('customer.payments.index');
        Route::get('/payments/{payment}', [\App\Http\Controllers\Customer\PaymentController::class, 'show'])->name('customer.payments.show');
        Route::get('/invoices/{invoice}/pay', [\App\Http\Controllers\Customer\PaymentController::class, 'selectMethod'])->name('customer.payment.select-method');
        Route::post('/invoices/{invoice}/pay', [\App\Http\Controllers\Customer\PaymentController::class, 'initiate'])->name('customer.payment.initiate');
        Route::get('/invoices/{invoice}/pay/mpesa/verify', [\App\Http\Controllers\Customer\PaymentController::class, 'verifyMpesa'])->name('customer.payment.verify-mpesa');
        Route::get('/invoices/{invoice}/payment/success', [\App\Http\Controllers\Customer\PaymentController::class, 'success'])->name('customer.payment.success');
        Route::get('/invoices/{invoice}/payment/stripe/success', [\App\Http\Controllers\Customer\PaymentController::class, 'stripeSuccess'])->name('customer.payment.stripe.success');
        Route::get('/invoices/{invoice}/payment/stripe/cancel', [\App\Http\Controllers\Customer\PaymentController::class, 'stripeCancel'])->name('customer.payment.stripe.cancel');
        Route::get('/invoices/{invoice}/payment/paypal/success', [\App\Http\Controllers\Customer\PaymentController::class, 'paypalSuccess'])->name('customer.payment.paypal.success');
        Route::get('/invoices/{invoice}/payment/paypal/cancel', [\App\Http\Controllers\Customer\PaymentController::class, 'paypalCancel'])->name('customer.payment.paypal.cancel');

        // Container management
        Route::get('my/services/{service}/container', [\App\Http\Controllers\Customer\ContainerController::class, 'show'])->name('customer.services.container.show');
        Route::post('my/services/{service}/container/restart', [\App\Http\Controllers\Customer\ContainerController::class, 'restart'])->name('customer.services.container.restart');
        Route::post('my/services/{service}/container/stop', [\App\Http\Controllers\Customer\ContainerController::class, 'stop'])->name('customer.services.container.stop');
        Route::post('my/services/{service}/container/start', [\App\Http\Controllers\Customer\ContainerController::class, 'start'])->name('customer.services.container.start');
        Route::get('my/services/{service}/container/logs', [\App\Http\Controllers\Customer\ContainerController::class, 'logs'])->name('customer.services.container.logs');
        Route::get('my/services/{service}/container/metrics', [\App\Http\Controllers\Customer\ContainerController::class, 'metrics'])->name('customer.services.container.metrics');
        Route::post('my/services/{service}/container/domains', [\App\Http\Controllers\Customer\ContainerController::class, 'bindDomain'])->name('customer.services.container.domains.bind');
        Route::delete('my/services/{service}/container/domains/{domain}', [\App\Http\Controllers\Customer\ContainerController::class, 'unbindDomain'])->name('customer.services.container.domains.unbind');
        Route::post('my/services/{service}/container/domains/{domain}/ssl', [\App\Http\Controllers\Customer\ContainerController::class, 'enableSsl'])->name('customer.services.container.domains.ssl');

        Route::get('/my/domains/available', fn() => view('customer.domains.available', ['extensions' => \App\Models\DomainExtension::where('enabled', true)->get()]))->name('customer.domains.available');
        Route::get('/my/tickets', fn() => view('customer.tickets.index'))->name('customer.tickets.index');
    });

    // Profile (accessible to all authenticated users)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/security', [ProfileController::class, 'security'])->name('profile.security');
    Route::post('/security/logout-other-sessions', [ProfileController::class, 'logoutOtherSessions'])->name('profile.logout-other-sessions');
});

// M-Pesa callback (public, no auth required)
Route::post('/mpesa/callback', [\App\Http\Controllers\Customer\MpesaController::class, 'callback'])->name('mpesa.callback');

require __DIR__.'/auth.php';
