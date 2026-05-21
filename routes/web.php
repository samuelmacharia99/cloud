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
    Route::post('/exit-impersonation', function () {
        // Check if impersonating from reseller or admin
        if (session('impersonating_reseller')) {
            $resellerId = session('impersonating_reseller');
            session()->forget(['impersonating_reseller', 'impersonating_user_id']);
            auth()->logout();
            auth()->loginUsingId($resellerId);
            return redirect()->route('reseller.customers.index')->with('success', 'Exited customer view.');
        } elseif (session('impersonating')) {
            $adminId = session('impersonating');
            session()->forget(['impersonating', 'impersonating_user_id']);
            auth()->logout();
            auth()->loginUsingId($adminId);
            return redirect()->route('admin.customers.index')->with('success', 'Exited customer view.');
        }
        return redirect()->route('dashboard');
    })->name('exit-impersonation');
});

// Public domain search and checkout (no authentication required)
Route::get('/search-domains', [\App\Http\Controllers\Customer\DomainSearchController::class, 'search'])->name('domains.search.public');
Route::post('/sync-cart', [\App\Http\Controllers\Customer\CheckoutController::class, 'syncCart'])->name('checkout.sync-cart');
Route::get('/domain-checkout', [\App\Http\Controllers\Customer\CheckoutController::class, 'showPublic'])->name('checkout.show.public');
Route::post('/domain-checkout', [\App\Http\Controllers\Customer\CheckoutController::class, 'processPublic'])->name('checkout.process.public');

// Payment webhooks (public, no authentication required)
Route::post('/webhooks/c2b', [\App\Http\Controllers\PaymentWebhookController::class, 'mpesaCallback'])->name('payment.mpesa.callback');
Route::post('/webhooks/mpesa/callback', [\App\Http\Controllers\PaymentWebhookController::class, 'mpesaCallback'])->name('payment.mpesa.callback.legacy');
Route::post('/webhooks/stripe', [\App\Http\Controllers\Customer\PaymentController::class, 'stripeWebhook'])->name('payment.stripe.webhook');
Route::post('/webhooks/paypal', [\App\Http\Controllers\Customer\PaymentController::class, 'paypalWebhook'])->name('payment.paypal.webhook');

// Legal pages (public, no authentication required)
Route::get('/terms', fn() => view('legal.terms'))->name('terms');
Route::get('/privacy', fn() => view('legal.privacy'))->name('privacy');

Route::middleware(['auth', 'skip.verification.if.impersonating'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Admin-only routes
    Route::middleware('admin')->group(function () {
        Route::resource('admin/customers', \App\Http\Controllers\Admin\CustomerController::class)->names('admin.customers');
        Route::post('admin/customers/{customer}/impersonate', [\App\Http\Controllers\Admin\CustomerController::class, 'impersonate'])->name('admin.customers.impersonate');
        Route::post('admin/customers/{customer}/add-domain', [\App\Http\Controllers\Admin\CustomerController::class, 'addDomain'])->name('admin.customers.add-domain');
        Route::post('admin/customers/{customer}/add-service', [\App\Http\Controllers\Admin\CustomerController::class, 'addService'])->name('admin.customers.add-service');
        Route::get('admin/customers/{customer}/generate-username', [\App\Http\Controllers\Admin\CustomerController::class, 'generateUsername'])->name('admin.customers.generate-username');
        Route::get('admin/generate-password', [\App\Http\Controllers\Admin\CustomerController::class, 'generatePassword'])->name('admin.customers.generate-password');
        Route::post('admin/customers/{customer}/invoice', [\App\Http\Controllers\Admin\CustomerController::class, 'createInvoice'])->name('admin.customers.create-invoice');
        Route::post('admin/customers/{customer}/convert-to-reseller', [\App\Http\Controllers\Admin\CustomerController::class, 'convertToReseller'])->name('admin.customers.convert-to-reseller');
        Route::post('admin/customers/{customer}/transfer-to-reseller', [\App\Http\Controllers\Admin\CustomerController::class, 'transferToReseller'])->name('admin.customers.transfer-to-reseller');
        Route::resource('admin/products', \App\Http\Controllers\Admin\ProductController::class)->names('admin.products');
        Route::resource('admin/invoices', \App\Http\Controllers\Admin\InvoiceController::class)->names('admin.invoices');
        Route::get('admin/invoices/{invoice}/download', [\App\Http\Controllers\Admin\InvoiceController::class, 'download'])->name('admin.invoices.download');
        Route::post('admin/invoices/{invoice}/payments', [\App\Http\Controllers\Admin\InvoiceController::class, 'addPayment'])->name('admin.invoices.add-payment');
        Route::post('admin/invoices/{invoice}/mark-paid', [\App\Http\Controllers\Admin\InvoiceController::class, 'markAsPaid'])->name('admin.invoices.mark-paid');
        Route::resource('admin/payments', \App\Http\Controllers\Admin\PaymentController::class)->names('admin.payments');
        Route::post('admin/payments/{payment}/approve-manual', [\App\Http\Controllers\Admin\PaymentController::class, 'approveManual'])->name('admin.payments.approve-manual');
        Route::post('admin/payments/{payment}/reject-manual', [\App\Http\Controllers\Admin\PaymentController::class, 'rejectManual'])->name('admin.payments.reject-manual');
        Route::resource('admin/services', \App\Http\Controllers\Admin\ServiceController::class)->names('admin.services');
        Route::get('admin/services/create', [\App\Http\Controllers\Admin\ServiceController::class, 'create'])->name('admin.services.create');
        Route::post('admin/services', [\App\Http\Controllers\Admin\ServiceController::class, 'store'])->name('admin.services.store');
        // DirectAdmin cross-node package consistency (must be registered BEFORE the
        // nodes resource route so /admin/nodes/{node} doesn't swallow the URL).
        Route::get('admin/shared-hosting/package-consistency', [\App\Http\Controllers\Admin\NodeController::class, 'packageConsistency'])->name('admin.shared-hosting.package-consistency');
        Route::resource('admin/nodes', \App\Http\Controllers\Admin\NodeController::class)->names('admin.nodes');
        Route::post('admin/nodes/{node}/status', [\App\Http\Controllers\Admin\NodeController::class, 'updateStatus'])->name('admin.nodes.update-status');
        Route::post('admin/nodes/{node}/test-connection', [\App\Http\Controllers\Admin\NodeController::class, 'testConnection'])->name('admin.nodes.test-connection');
        Route::post('admin/nodes/{node}/test-health', [\App\Http\Controllers\Admin\NodeController::class, 'testHealth'])->name('admin.nodes.test-health');
        Route::post('admin/nodes/{node}/utilization', [\App\Http\Controllers\Admin\NodeController::class, 'updateUtilization'])->name('admin.nodes.update-utilization');
        Route::post('admin/nodes/{node}/heartbeat', [\App\Http\Controllers\Admin\NodeController::class, 'heartbeat'])->name('admin.nodes.heartbeat');
        Route::post('admin/nodes/{node}/sync-packages', [\App\Http\Controllers\Admin\NodeController::class, 'syncDirectAdminPackages'])->name('admin.nodes.sync-packages');
        Route::get('admin/nodes/{node}/packages-json', [\App\Http\Controllers\Admin\NodeController::class, 'packagesJson'])->name('admin.nodes.packages-json');
        Route::get('admin/nodes-status-json', [\App\Http\Controllers\Admin\NodeController::class, 'statusJson'])->name('admin.nodes.status-json');
        Route::delete('admin/nodes/{node}', [\App\Http\Controllers\Admin\NodeController::class, 'delete'])->name('admin.nodes.delete');
        Route::resource('admin/domains', \App\Http\Controllers\Admin\DomainController::class)->names('admin.domains');
        Route::post('admin/domains/{domain}/generate-invoice', [\App\Http\Controllers\Admin\DomainController::class, 'generateInvoice'])->name('admin.domains.generate-invoice');
        Route::get('admin/domains-pricing', [\App\Http\Controllers\Admin\DomainController::class, 'pricing'])->name('admin.domains.pricing');
        Route::post('admin/domains-pricing', [\App\Http\Controllers\Admin\DomainController::class, 'storePricing'])->name('admin.domains.pricing.store');
        Route::post('admin/domain-extensions', [\App\Http\Controllers\Admin\DomainController::class, 'storeExtension'])->name('admin.domain-extensions.store');
        Route::resource('admin/reseller-packages', \App\Http\Controllers\Admin\ResellerPackageController::class)->names('admin.reseller-packages');
        Route::resource('admin/orders', \App\Http\Controllers\Admin\OrderController::class)->only(['index', 'show'])->names('admin.orders');
        Route::post('admin/orders/{order}/mark-complete', [\App\Http\Controllers\Admin\OrderController::class, 'markComplete'])->name('admin.orders.mark-complete');
        Route::get('admin/resellers', [\App\Http\Controllers\Admin\ResellerController::class, 'index'])->name('admin.resellers.index');
        Route::get('admin/resellers/create', [\App\Http\Controllers\Admin\ResellerController::class, 'create'])->name('admin.resellers.create');
        Route::post('admin/resellers', [\App\Http\Controllers\Admin\ResellerController::class, 'store'])->name('admin.resellers.store');
        Route::get('admin/resellers/{user}/edit', [\App\Http\Controllers\Admin\ResellerController::class, 'edit'])->name('admin.resellers.edit');
        Route::put('admin/resellers/{user}', [\App\Http\Controllers\Admin\ResellerController::class, 'update'])->name('admin.resellers.update');
        Route::get('admin/resellers/{user}', [\App\Http\Controllers\Admin\ResellerController::class, 'show'])->name('admin.resellers.show');
        Route::post('admin/resellers/{user}/promote', [\App\Http\Controllers\Admin\ResellerController::class, 'promote'])->name('admin.resellers.promote');
        Route::post('admin/resellers/{user}/demote', [\App\Http\Controllers\Admin\ResellerController::class, 'demote'])->name('admin.resellers.demote');
        Route::post('admin/resellers/{user}/assign-package', [\App\Http\Controllers\Admin\ResellerController::class, 'assignPackage'])->name('admin.resellers.assign-package');
        Route::post('admin/resellers/{user}/upgrade-package', [\App\Http\Controllers\Admin\ResellerController::class, 'upgradePackage'])->name('admin.resellers.upgrade-package');
        Route::post('admin/resellers/{user}/update-billing', [\App\Http\Controllers\Admin\ResellerController::class, 'updateBilling'])->name('admin.resellers.update-billing');
        Route::post('admin/resellers/{user}/impersonate', [\App\Http\Controllers\Admin\ResellerController::class, 'impersonate'])->name('admin.resellers.impersonate');
        Route::post('admin/resellers/{user}/add-domain', [\App\Http\Controllers\Admin\ResellerController::class, 'addDomain'])->name('admin.resellers.add-domain');
        Route::post('admin/resellers/{user}/add-service', [\App\Http\Controllers\Admin\ResellerController::class, 'addService'])->name('admin.resellers.add-service');
        Route::get('admin/settings', [\App\Http\Controllers\Admin\SettingController::class, 'index'])->name('admin.settings.index');
        Route::post('admin/settings', [\App\Http\Controllers\Admin\SettingController::class, 'update'])->name('admin.settings.update');
        Route::post('admin/settings/upload-file', [\App\Http\Controllers\Admin\SettingController::class, 'uploadFile'])->name('admin.settings.upload-file');
        Route::post('admin/settings/test-smtp', [\App\Http\Controllers\Admin\SettingController::class, 'testSmtp'])->name('admin.settings.test-smtp');
        Route::post('admin/settings/test-sms', [\App\Http\Controllers\Admin\SettingController::class, 'testSms'])->name('admin.settings.test-sms');
        Route::post('admin/settings/test-mpesa', [\App\Http\Controllers\Admin\SettingController::class, 'testMpesa'])->name('admin.settings.test-mpesa');
        Route::post('admin/settings/register-mpesa-urls', [\App\Http\Controllers\Admin\SettingController::class, 'registerMpesaUrls'])->name('admin.settings.register-mpesa-urls');
        Route::post('admin/settings/simulate-mpesa-payment', [\App\Http\Controllers\Admin\SettingController::class, 'simulateMpesaPayment'])->name('admin.settings.simulate-mpesa-payment');
        Route::post('admin/settings/debug-log', [\App\Http\Controllers\Admin\SettingController::class, 'debugLog'])->name('admin.settings.debug-log');
        Route::post('admin/settings/refresh-currencies', [\App\Http\Controllers\Admin\SettingController::class, 'refreshCurrencies'])->name('admin.settings.refresh-currencies');

        // Manual Payment Settings
        Route::get('admin/manual-payment', [\App\Http\Controllers\Admin\ManualPaymentController::class, 'index'])->name('admin.manual-payment.index');
        Route::post('admin/manual-payment', [\App\Http\Controllers\Admin\ManualPaymentController::class, 'update'])->name('admin.manual-payment.update');

        // Admin Profile
        Route::get('admin/profile', [\App\Http\Controllers\Admin\ProfileController::class, 'edit'])->name('admin.profile.edit');
        Route::patch('admin/profile', [\App\Http\Controllers\Admin\ProfileController::class, 'update'])->name('admin.profile.update');
        Route::post('admin/profile/two-factor/enable', [\App\Http\Controllers\Admin\ProfileController::class, 'enableTwoFactor'])->name('admin.profile.two-factor.enable');
        Route::post('admin/profile/two-factor/disable', [\App\Http\Controllers\Admin\ProfileController::class, 'disableTwoFactor'])->name('admin.profile.two-factor.disable');
        Route::post('admin/profile/two-factor/regenerate-codes', [\App\Http\Controllers\Admin\ProfileController::class, 'regenerateRecoveryCodes'])->name('admin.profile.two-factor.regenerate-codes');

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

        // SMS Templates (AJAX)
        Route::put('admin/sms-templates/{smsTemplate}', [\App\Http\Controllers\Admin\SmsTemplateController::class, 'update'])->name('admin.sms-templates.update');
        Route::post('admin/sms-templates/{smsTemplate}/reset', [\App\Http\Controllers\Admin\SmsTemplateController::class, 'reset'])->name('admin.sms-templates.reset');

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
        Route::post('admin/services/{service}/cancel', [\App\Http\Controllers\Admin\ServiceController::class, 'cancel'])->name('admin.services.cancel');
        Route::post('admin/services/{service}/refresh-status', [\App\Http\Controllers\Admin\ServiceController::class, 'refreshStatus'])->name('admin.services.refresh-status');
        Route::post('admin/services/{service}/test-directadmin', [\App\Http\Controllers\Admin\ServiceController::class, 'testDirectAdminConnection'])->name('admin.services.test-directadmin');
        Route::post('admin/services/{service}/resend-credentials', [\App\Http\Controllers\Admin\ServiceController::class, 'resendCredentials'])->name('admin.services.resend-credentials');

        // Container management
        Route::resource('admin/container-templates', \App\Http\Controllers\Admin\ContainerTemplateController::class)->names('admin.container-templates');
        Route::post('admin/services/{service}/container/restart', [\App\Http\Controllers\Admin\ContainerController::class, 'restart'])->name('admin.services.container.restart');
        Route::post('admin/services/{service}/container/suspend', [\App\Http\Controllers\Admin\ContainerController::class, 'suspend'])->name('admin.services.container.suspend');
        Route::post('admin/services/{service}/container/stop', [\App\Http\Controllers\Admin\ContainerController::class, 'stop'])->name('admin.services.container.stop');
        Route::post('admin/services/{service}/container/start', [\App\Http\Controllers\Admin\ContainerController::class, 'start'])->name('admin.services.container.start');
        Route::get('admin/services/{service}/container/logs', [\App\Http\Controllers\Admin\ContainerController::class, 'logs'])->name('admin.services.container.logs');
        Route::get('admin/services/{service}/container/metrics', [\App\Http\Controllers\Admin\ContainerController::class, 'metrics'])->name('admin.services.container.metrics');
        Route::post('admin/services/{service}/container/redeploy', [\App\Http\Controllers\Admin\ContainerController::class, 'redeploy'])->name('admin.services.container.redeploy');
        Route::get('admin/services/{service}/container/edit', [\App\Http\Controllers\Admin\ContainerController::class, 'edit'])->name('admin.services.container.edit');
        Route::patch('admin/services/{service}/container', [\App\Http\Controllers\Admin\ContainerController::class, 'update'])->name('admin.services.container.update');
        Route::post('admin/services/{service}/container/provision', [\App\Http\Controllers\Admin\ContainerController::class, 'provision'])->name('admin.services.container.provision');
        Route::post('admin/services/{service}/container/domains', [\App\Http\Controllers\Admin\ContainerController::class, 'bindDomain'])->name('admin.services.container.domains.bind');
        Route::delete('admin/services/{service}/container/domains/{domain}', [\App\Http\Controllers\Admin\ContainerController::class, 'unbindDomain'])->name('admin.services.container.domains.unbind');
        Route::post('admin/services/{service}/container/domains/{domain}/ssl', [\App\Http\Controllers\Admin\ContainerController::class, 'enableSsl'])->name('admin.services.container.domains.ssl');

        // Container backups
        Route::post('admin/services/{service}/container/backups', [\App\Http\Controllers\Admin\ContainerController::class, 'createBackup'])->name('admin.services.container.backups.create');
        Route::post('admin/services/{service}/container/backups/{backup}/restore', [\App\Http\Controllers\Admin\ContainerController::class, 'restoreBackup'])->name('admin.services.container.backups.restore');
        Route::delete('admin/services/{service}/container/backups/{backup}', [\App\Http\Controllers\Admin\ContainerController::class, 'deleteBackup'])->name('admin.services.container.backups.delete');

        // Container migration
        Route::get('admin/services/{service}/container/migrate', [\App\Http\Controllers\Admin\ContainerMigrationController::class, 'index'])->name('admin.services.container.migrate');
        Route::post('admin/services/{service}/container/migrate', [\App\Http\Controllers\Admin\ContainerMigrationController::class, 'migrate'])->name('admin.services.container.migrate.confirm');
        Route::post('admin/nodes/{node}/migrate-containers', [\App\Http\Controllers\Admin\ContainerMigrationController::class, 'migrateNode'])->name('admin.nodes.migrate-containers');

        // Admin Ticket Management
        Route::resource('admin/tickets', \App\Http\Controllers\Admin\TicketController::class)->names('tickets');
        Route::post('admin/tickets/{ticket}/reply', [\App\Http\Controllers\Admin\TicketController::class, 'reply'])->name('tickets.reply');
        Route::patch('admin/tickets/{ticket}/status', [\App\Http\Controllers\Admin\TicketController::class, 'updateStatus'])->name('tickets.updateStatus');
        Route::patch('admin/tickets/{ticket}/assign', [\App\Http\Controllers\Admin\TicketController::class, 'assign'])->name('tickets.assign');

        // Reseller Wallet Management
        Route::get('admin/reseller-wallets', [\App\Http\Controllers\Admin\ResellerWalletController::class, 'index'])->name('admin.reseller-wallets.index');
        Route::get('admin/reseller-wallets/{reseller}', [\App\Http\Controllers\Admin\ResellerWalletController::class, 'show'])->name('admin.reseller-wallets.show');
        Route::post('admin/reseller-wallets/{reseller}/adjust', [\App\Http\Controllers\Admin\ResellerWalletController::class, 'adjust'])->name('admin.reseller-wallets.adjust');
        Route::get('admin/reseller-wallets/{reseller}/export', [\App\Http\Controllers\Admin\ResellerWalletController::class, 'exportPdf'])->name('admin.reseller-wallets.export');

        // Domain Orders Management
        Route::get('admin/domain-orders', [\App\Http\Controllers\Admin\DomainOrderController::class, 'index'])->name('admin.domain-orders.index');
        Route::get('admin/domain-orders/{order}', [\App\Http\Controllers\Admin\DomainOrderController::class, 'show'])->name('admin.domain-orders.show');
        Route::post('admin/domain-orders/{order}/complete', [\App\Http\Controllers\Admin\DomainOrderController::class, 'complete'])->name('admin.domain-orders.complete');
        Route::post('admin/domain-orders/{order}/fail', [\App\Http\Controllers\Admin\DomainOrderController::class, 'fail'])->name('admin.domain-orders.fail');

        // Domain renewals
        Route::get('admin/domain-renewals', [\App\Http\Controllers\Admin\DomainRenewalController::class, 'index'])->name('admin.domain-renewals.index');
        Route::get('admin/domain-renewals/{renewal}', [\App\Http\Controllers\Admin\DomainRenewalController::class, 'show'])->name('admin.domain-renewals.show');
        Route::post('admin/domain-renewals/{renewal}/complete', [\App\Http\Controllers\Admin\DomainRenewalController::class, 'complete'])->name('admin.domain-renewals.complete');
        Route::post('admin/domain-renewals/{renewal}/fail', [\App\Http\Controllers\Admin\DomainRenewalController::class, 'fail'])->name('admin.domain-renewals.fail');
        Route::post('admin/domain-renewals/{renewal}/expire', [\App\Http\Controllers\Admin\DomainRenewalController::class, 'expire'])->name('admin.domain-renewals.expire');
    });

    // Reseller-only routes
    Route::middleware('reseller')->group(function () {
        Route::resource('reseller/customers', \App\Http\Controllers\Reseller\CustomerController::class)->names('reseller.customers');
        Route::post('reseller/customers/{customer}/impersonate', [\App\Http\Controllers\Reseller\CustomerController::class, 'impersonate'])->name('reseller.customers.impersonate');
        Route::post('reseller/exit-impersonation', [\App\Http\Controllers\Reseller\CustomerController::class, 'exitImpersonation'])->name('reseller.exit-impersonation');
        Route::resource('reseller/catalog', \App\Http\Controllers\Reseller\CatalogController::class)->names('reseller.catalog');
        Route::get('reseller/domains', [\App\Http\Controllers\Reseller\DomainController::class, 'index'])->name('reseller.domains.index');
        Route::get('reseller/domains-pricing', [\App\Http\Controllers\Reseller\DomainPricingController::class, 'index'])->name('reseller.domains.pricing');
        Route::post('reseller/domains-pricing', [\App\Http\Controllers\Reseller\DomainPricingController::class, 'update'])->name('reseller.domains.pricing.update');
        Route::get('api/reseller/domains/pricing/{extension}', [\App\Http\Controllers\Reseller\DomainController::class, 'getPricing'])->name('reseller.domains.pricing.api');

        // Settings
        Route::get('reseller/settings', [\App\Http\Controllers\Reseller\SettingController::class, 'index'])->name('reseller.settings.index');
        Route::post('reseller/settings/mpesa', [\App\Http\Controllers\Reseller\SettingController::class, 'updateMpesa'])->name('reseller.settings.mpesa.update');
        Route::post('reseller/settings/mpesa/register-urls', [\App\Http\Controllers\Reseller\SettingController::class, 'registerMpesaUrls'])->name('reseller.settings.mpesa.register-urls');
        Route::post('reseller/settings/sms', [\App\Http\Controllers\Reseller\SettingController::class, 'updateSms'])->name('reseller.settings.sms.update');
        Route::post('reseller/settings/sms/test', [\App\Http\Controllers\Reseller\SettingController::class, 'testSms'])->name('reseller.settings.sms.test');
        Route::post('reseller/settings/smtp', [\App\Http\Controllers\Reseller\SettingController::class, 'updateSmtp'])->name('reseller.settings.smtp.update');
        Route::post('reseller/settings/smtp/test', [\App\Http\Controllers\Reseller\SettingController::class, 'testSmtp'])->name('reseller.settings.smtp.test');
        Route::post('reseller/settings/branding', [\App\Http\Controllers\Reseller\SettingController::class, 'updateBranding'])->name('reseller.settings.branding.update');
        Route::post('reseller/settings/branding/upload', [\App\Http\Controllers\Reseller\SettingController::class, 'uploadBrandingFile'])->name('reseller.settings.branding.upload');
        Route::delete('reseller/settings/branding/file', [\App\Http\Controllers\Reseller\SettingController::class, 'deleteBrandingFile'])->name('reseller.settings.branding.delete');
        Route::get('reseller/settings/branding/ssl/check-dns', [\App\Http\Controllers\Reseller\SettingController::class, 'checkSslDns'])->name('reseller.settings.branding.ssl.check-dns');
        Route::post('reseller/settings/branding/ssl/issue', [\App\Http\Controllers\Reseller\SettingController::class, 'issueSsl'])->name('reseller.settings.branding.ssl.issue');
        Route::post('reseller/settings/branding/ssl/renew', [\App\Http\Controllers\Reseller\SettingController::class, 'renewSsl'])->name('reseller.settings.branding.ssl.renew');

        Route::get('my/packages', [\App\Http\Controllers\Reseller\PackageController::class, 'index'])->name('reseller.packages.index');
        Route::post('my/packages/{package}/subscribe', [\App\Http\Controllers\Reseller\PackageController::class, 'subscribe'])->name('reseller.packages.subscribe');

        // Invoice Management
        Route::resource('reseller/invoices', \App\Http\Controllers\Reseller\InvoiceController::class)->only(['index', 'show'])->names('reseller.invoices');
        Route::get('reseller/invoices/{invoice}/download', [\App\Http\Controllers\Reseller\InvoiceController::class, 'download'])->name('reseller.invoices.download');

        // Wallet Management
        Route::get('reseller/wallet', [\App\Http\Controllers\Reseller\WalletController::class, 'index'])->name('reseller.wallet.index');
        Route::post('reseller/wallet/topup', [\App\Http\Controllers\Reseller\WalletController::class, 'initiateTopup'])->name('reseller.wallet.topup');
        Route::get('reseller/wallet/topup/status/{invoice}', [\App\Http\Controllers\Reseller\WalletController::class, 'checkTopupStatus'])->name('reseller.wallet.topup.status');
        Route::get('reseller/wallet/transactions', [\App\Http\Controllers\Reseller\WalletController::class, 'transactions'])->name('reseller.wallet.transactions');
        Route::get('reseller/wallet/export', [\App\Http\Controllers\Reseller\WalletController::class, 'exportPdf'])->name('reseller.wallet.export');

        // Domain Orders
        Route::get('reseller/domain-orders', [\App\Http\Controllers\Reseller\DomainPushController::class, 'index'])->name('reseller.domain-orders.index');
        Route::post('reseller/domain-orders/{order}/push', [\App\Http\Controllers\Reseller\DomainPushController::class, 'push'])->name('reseller.domain-orders.push');
        Route::post('reseller/domain-orders/{order}/retry', [\App\Http\Controllers\Reseller\DomainPushController::class, 'retry'])->name('reseller.domain-orders.retry');

        // Reseller Servers
        Route::get('reseller/servers', [\App\Http\Controllers\Reseller\ServerController::class, 'index'])->name('reseller.servers.index');
        Route::post('reseller/servers/order', [\App\Http\Controllers\Reseller\ServerController::class, 'order'])->name('reseller.servers.order');

        // Reseller Cart
        Route::get('reseller/cart', [\App\Http\Controllers\Reseller\CartController::class, 'index'])->name('reseller.cart.index');
        Route::post('reseller/cart/add', [\App\Http\Controllers\Reseller\CartController::class, 'add'])->name('reseller.cart.add');
        Route::delete('reseller/cart/{key}', [\App\Http\Controllers\Reseller\CartController::class, 'remove'])->name('reseller.cart.remove');
        Route::post('reseller/cart/clear', [\App\Http\Controllers\Reseller\CartController::class, 'clear'])->name('reseller.cart.clear');

        // Reseller Checkout
        Route::get('reseller/checkout', [\App\Http\Controllers\Reseller\CheckoutController::class, 'show'])->name('reseller.checkout.show');
        Route::post('reseller/checkout', [\App\Http\Controllers\Reseller\CheckoutController::class, 'process'])->name('reseller.checkout.process');

        // Reseller Payments
        Route::get('reseller/invoices/{invoice}/pay', [\App\Http\Controllers\Reseller\PaymentController::class, 'selectMethod'])->name('reseller.payment.select-method');
        Route::post('reseller/invoices/{invoice}/pay', [\App\Http\Controllers\Reseller\PaymentController::class, 'initiate'])->name('reseller.payment.initiate');
        Route::get('reseller/invoices/{invoice}/pay/mpesa/verify', [\App\Http\Controllers\Reseller\PaymentController::class, 'verifyMpesa'])->name('reseller.payment.verify-mpesa');
        Route::get('reseller/invoices/{invoice}/pay/mpesa/status', [\App\Http\Controllers\Reseller\PaymentController::class, 'mpesaStatus'])->name('reseller.payment.mpesa-status');
        Route::get('reseller/invoices/{invoice}/payment/success', [\App\Http\Controllers\Reseller\PaymentController::class, 'success'])->name('reseller.payment.success');
        Route::get('reseller/invoices/{invoice}/payment/stripe/success', [\App\Http\Controllers\Reseller\PaymentController::class, 'stripeSuccess'])->name('reseller.payment.stripe.success');
        Route::get('reseller/invoices/{invoice}/payment/stripe/cancel', [\App\Http\Controllers\Reseller\PaymentController::class, 'stripeCancel'])->name('reseller.payment.stripe.cancel');
        Route::get('reseller/invoices/{invoice}/payment/paypal/success', [\App\Http\Controllers\Reseller\PaymentController::class, 'paypalSuccess'])->name('reseller.payment.paypal.success');
        Route::get('reseller/invoices/{invoice}/payment/paypal/cancel', [\App\Http\Controllers\Reseller\PaymentController::class, 'paypalCancel'])->name('reseller.payment.paypal.cancel');
        Route::get('reseller/invoices/{invoice}/payment/manual', [\App\Http\Controllers\Reseller\PaymentController::class, 'manualForm'])->name('reseller.payment.manual-form');
        Route::post('reseller/invoices/{invoice}/payment/manual', [\App\Http\Controllers\Reseller\PaymentController::class, 'manualSubmit'])->name('reseller.payment.manual-submit');
        Route::get('reseller/payments/{payment}/submitted', [\App\Http\Controllers\Reseller\PaymentController::class, 'manualSubmitted'])->name('reseller.payment.manual-submitted');
    });

    // Customer-only routes
    Route::middleware('customer')->group(function () {
        Route::get('/my/services', [\App\Http\Controllers\Customer\ServiceController::class, 'index'])->name('customer.services.index');
        Route::get('/my/services/{service}', [\App\Http\Controllers\Customer\ServiceController::class, 'show'])->name('customer.services.show');
        Route::post('/my/services/{service}/cancel', [\App\Http\Controllers\Customer\ServiceController::class, 'cancel'])->name('customer.services.cancel');
        Route::post('/my/services/{service}/renew', [\App\Http\Controllers\Customer\ServiceController::class, 'renew'])->name('customer.services.renew');
        Route::get('/my/servers', [\App\Http\Controllers\Customer\ServerController::class, 'index'])->name('customer.servers.index');
        Route::post('/my/servers/order', [\App\Http\Controllers\Customer\ServerController::class, 'order'])->name('customer.servers.order');
        Route::resource('my/orders', \App\Http\Controllers\Customer\OrderController::class)->only(['index', 'show'])->names('customer.orders');
        Route::resource('my/invoices', \App\Http\Controllers\Customer\InvoiceController::class)->only(['index', 'show'])->names('customer.invoices');
        Route::get('my/invoices/{invoice}/download', [\App\Http\Controllers\Customer\InvoiceController::class, 'download'])->name('customer.invoices.download');
        Route::get('my/invoices/{invoice}/preview', [\App\Http\Controllers\Customer\InvoiceController::class, 'preview'])->name('customer.invoices.preview');
        Route::resource('my/payments', \App\Http\Controllers\Customer\PaymentController::class)->only(['index', 'show'])->names('customer.payments');

        // Shopping experience
        Route::get('/select-techstack', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'selectTechstack'])->name('customer.select-techstack');
        Route::post('/confirm-techstack', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'confirmTechstack'])->name('customer.confirm-techstack');
        Route::get('/api/languages/{language}/databases', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'getAvailableDatabases'])->name('api.languages.databases');
        Route::get('/api/databases/{database}/languages', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'getAvailableLanguages'])->name('api.databases.languages');
        Route::get('/api/products', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'getAvailableProducts'])->name('api.products');
        Route::get('/deploy-service', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'index'])->name('customer.deploy-service');
        Route::get('/browse-services', [\App\Http\Controllers\Customer\ServiceBrowserController::class, 'browse'])->name('customer.browse-services');
        Route::get('/my/domains', [\App\Http\Controllers\Customer\DomainController::class, 'index'])->name('customer.domains.index');
        Route::get('/my/domains/transfer', [\App\Http\Controllers\Customer\DomainController::class, 'showTransferForm'])->name('customer.domains.transfer-form');
        Route::post('/my/domains/transfer', [\App\Http\Controllers\Customer\DomainController::class, 'processTransfer'])->name('customer.domains.process-transfer');
        Route::get('/my/domains/transfer/checkout', [\App\Http\Controllers\Customer\DomainController::class, 'showTransferCheckout'])->name('customer.domains.transfer-checkout');
        Route::post('/my/domains/transfer/checkout/confirm', [\App\Http\Controllers\Customer\DomainController::class, 'confirmTransferCheckout'])->name('customer.domains.transfer-checkout-confirm');
        Route::get('/my/domains/{domain}/transfer', [\App\Http\Controllers\Customer\DomainController::class, 'showTransferDetails'])->name('customer.domains.transfer-details');
        Route::post('/my/domains/{domain}/transfer/cancel', [\App\Http\Controllers\Customer\DomainController::class, 'cancelTransfer'])->name('customer.domains.cancel-transfer');

        // Domain renewal
        Route::post('/my/domains/{domain}/renew', [\App\Http\Controllers\Customer\DomainController::class, 'initiateRenewal'])->name('customer.domains.initiate-renewal');
        Route::get('/my/domains/renewal/checkout', [\App\Http\Controllers\Customer\DomainController::class, 'showRenewalCheckout'])->name('customer.domains.renewal-checkout');
        Route::post('/my/domains/renewal/checkout/confirm', [\App\Http\Controllers\Customer\DomainController::class, 'confirmRenewalCheckout'])->name('customer.domains.renewal-checkout-confirm');

        // DNS management
        Route::get('/my/domains/{domain}/dns', [\App\Http\Controllers\Customer\DnsController::class, 'index'])->name('customer.domains.dns.index');
        Route::get('/my/domains/{domain}/dns/nameservers', [\App\Http\Controllers\Customer\DnsController::class, 'nameservers'])->name('customer.domains.dns.nameservers');
        Route::post('/my/domains/{domain}/dns/nameservers', [\App\Http\Controllers\Customer\DnsController::class, 'updateNameservers'])->name('customer.domains.dns.update-nameservers');
        Route::get('/my/domains/{domain}/dns/records', [\App\Http\Controllers\Customer\DnsController::class, 'records'])->name('customer.domains.dns.records');
        Route::post('/my/domains/{domain}/dns/records', [\App\Http\Controllers\Customer\DnsController::class, 'addRecord'])->name('customer.domains.dns.add-record');
        Route::patch('/my/domains/{domain}/dns/records/{record}', [\App\Http\Controllers\Customer\DnsController::class, 'updateRecord'])->name('customer.domains.dns.update-record');
        Route::delete('/my/domains/{domain}/dns/records/{record}', [\App\Http\Controllers\Customer\DnsController::class, 'deleteRecord'])->name('customer.domains.dns.delete-record');

        Route::get('/domains/search', [\App\Http\Controllers\Customer\DomainSearchController::class, 'search'])->name('domains.search');

        // Shopping cart
        Route::get('/cart', [\App\Http\Controllers\Customer\CartController::class, 'index'])->name('customer.cart.index');
        Route::post('/cart/add', [\App\Http\Controllers\Customer\CartController::class, 'add'])->name('customer.cart.add');
        Route::delete('/cart/{key}', [\App\Http\Controllers\Customer\CartController::class, 'remove'])->name('customer.cart.remove');
        Route::post('/cart/clear', [\App\Http\Controllers\Customer\CartController::class, 'clear'])->name('customer.cart.clear');
        Route::post('/cart/check-domain', [\App\Http\Controllers\Customer\CartController::class, 'checkDomainAvailability'])->name('customer.cart.check-domain');
        Route::post('/cart/{key}/nameservers', [\App\Http\Controllers\Customer\CartController::class, 'updateNameservers'])->name('customer.cart.nameservers');

        // Checkout
        Route::get('/checkout', [\App\Http\Controllers\Customer\CheckoutController::class, 'show'])->name('customer.checkout.show');
        Route::post('/checkout', [\App\Http\Controllers\Customer\CheckoutController::class, 'process'])->name('customer.checkout.process');

        // Payment methods (resource routes already defined above, these are additional payment workflows)
        Route::get('/invoices/{invoice}/pay', [\App\Http\Controllers\Customer\PaymentController::class, 'selectMethod'])->name('customer.payment.select-method');
        Route::post('/invoices/{invoice}/pay', [\App\Http\Controllers\Customer\PaymentController::class, 'initiate'])->name('customer.payment.initiate');
        Route::get('/invoices/{invoice}/pay/mpesa/verify', [\App\Http\Controllers\Customer\PaymentController::class, 'verifyMpesa'])->name('customer.payment.verify-mpesa');
        Route::get('/invoices/{invoice}/pay/mpesa/status', [\App\Http\Controllers\Customer\PaymentController::class, 'mpesaStatus'])->name('customer.payment.mpesa-status');
        Route::get('/invoices/{invoice}/payment/success', [\App\Http\Controllers\Customer\PaymentController::class, 'success'])->name('customer.payment.success');
        Route::get('/invoices/{invoice}/payment/stripe/success', [\App\Http\Controllers\Customer\PaymentController::class, 'stripeSuccess'])->name('customer.payment.stripe.success');
        Route::get('/invoices/{invoice}/payment/stripe/cancel', [\App\Http\Controllers\Customer\PaymentController::class, 'stripeCancel'])->name('customer.payment.stripe.cancel');
        Route::get('/invoices/{invoice}/payment/paypal/success', [\App\Http\Controllers\Customer\PaymentController::class, 'paypalSuccess'])->name('customer.payment.paypal.success');
        Route::get('/invoices/{invoice}/payment/paypal/cancel', [\App\Http\Controllers\Customer\PaymentController::class, 'paypalCancel'])->name('customer.payment.paypal.cancel');
        Route::get('/invoices/{invoice}/payment/manual', [\App\Http\Controllers\Customer\PaymentController::class, 'manualPaymentForm'])->name('customer.payment.manual-form');
        Route::post('/invoices/{invoice}/payment/manual', [\App\Http\Controllers\Customer\PaymentController::class, 'submitManualPayment'])->name('customer.payment.manual-submit');
        Route::get('/payments/{payment}/submitted', [\App\Http\Controllers\Customer\PaymentController::class, 'manualPaymentSubmitted'])->name('customer.payment.manual-submitted');

        // Container management
        Route::get('my/services/{service}/container', [\App\Http\Controllers\Customer\ContainerController::class, 'show'])->name('customer.services.container.show');
        Route::post('my/services/{service}/container/restart', [\App\Http\Controllers\Customer\ContainerController::class, 'restart'])->name('customer.services.container.restart');
        Route::post('my/services/{service}/container/stop', [\App\Http\Controllers\Customer\ContainerController::class, 'stop'])->name('customer.services.container.stop');
        Route::post('my/services/{service}/container/start', [\App\Http\Controllers\Customer\ContainerController::class, 'start'])->name('customer.services.container.start');
        Route::get('my/services/{service}/container/logs', [\App\Http\Controllers\Customer\ContainerController::class, 'logs'])->name('customer.services.container.logs');
        Route::get('my/services/{service}/container/metrics', [\App\Http\Controllers\Customer\ContainerController::class, 'metrics'])->name('customer.services.container.metrics');
        Route::get('my/services/{service}/container/health', [\App\Http\Controllers\Customer\ContainerController::class, 'health'])->name('customer.services.container.health');
        Route::get('my/services/{service}/container/storage-stats', [\App\Http\Controllers\Customer\ContainerController::class, 'storageStats'])->name('customer.services.container.storage-stats');

        // Container file manager (throttled)
        Route::middleware(['throttle:60,1'])->group(function () {
            Route::get('my/services/{service}/container/files', [\App\Http\Controllers\Customer\ContainerFileController::class, 'index'])->name('customer.services.container.files.index');
            Route::get('my/services/{service}/container/files/download', [\App\Http\Controllers\Customer\ContainerFileController::class, 'download'])->name('customer.services.container.files.download');
            Route::post('my/services/{service}/container/files/upload', [\App\Http\Controllers\Customer\ContainerFileController::class, 'upload'])->middleware('throttle:10,1')->name('customer.services.container.files.upload');
            Route::delete('my/services/{service}/container/files', [\App\Http\Controllers\Customer\ContainerFileController::class, 'delete'])->name('customer.services.container.files.delete');
            Route::post('my/services/{service}/container/files/mkdir', [\App\Http\Controllers\Customer\ContainerFileController::class, 'mkdir'])->name('customer.services.container.files.mkdir');
        });

        Route::post('my/services/{service}/container/domains', [\App\Http\Controllers\Customer\ContainerController::class, 'bindDomain'])->name('customer.services.container.domains.bind');
        Route::delete('my/services/{service}/container/domains/{domain}', [\App\Http\Controllers\Customer\ContainerController::class, 'unbindDomain'])->name('customer.services.container.domains.unbind');
        Route::post('my/services/{service}/container/domains/{domain}/ssl', [\App\Http\Controllers\Customer\ContainerController::class, 'enableSsl'])->name('customer.services.container.domains.ssl');
        Route::post('my/services/{service}/container/backups', [\App\Http\Controllers\Customer\ContainerController::class, 'createBackup'])->name('customer.services.container.backups.create');
        Route::post('my/services/{service}/container/backups/{backup}/restore', [\App\Http\Controllers\Customer\ContainerController::class, 'restoreBackup'])->name('customer.services.container.backups.restore');
        Route::delete('my/services/{service}/container/backups/{backup}', [\App\Http\Controllers\Customer\ContainerController::class, 'deleteBackup'])->name('customer.services.container.backups.delete');

        Route::get('/my/domains/available', fn() => view('customer.domains.available', ['extensions' => \App\Models\DomainExtension::where('enabled', true)->get()]))->name('customer.domains.available');

        // Customer Ticket Management
        Route::resource('my/tickets', \App\Http\Controllers\Customer\TicketController::class)
            ->only(['index', 'show', 'create', 'store'])->names('customer.tickets');
        Route::post('my/tickets/{ticket}/reply', [\App\Http\Controllers\Customer\TicketController::class, 'reply'])->name('customer.tickets.reply');
        Route::patch('my/tickets/{ticket}/close', [\App\Http\Controllers\Customer\TicketController::class, 'close'])->name('customer.tickets.close');
    });

    // Profile (accessible to all authenticated users)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/security', [ProfileController::class, 'security'])->name('profile.security');
    Route::post('/security/logout-other-sessions', [ProfileController::class, 'logoutOtherSessions'])->name('profile.logout-other-sessions');
});

require __DIR__.'/auth.php';
