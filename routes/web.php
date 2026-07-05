<?php

use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\AdminReportsController;
use App\Http\Controllers\Admin\ContainerController;
use App\Http\Controllers\Admin\ContainerMigrationController;
use App\Http\Controllers\Admin\ContainerTemplateController;
use App\Http\Controllers\Admin\CreditController;
use App\Http\Controllers\Admin\CronController;
use App\Http\Controllers\Admin\CurrencyController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DatabaseTemplateController;
use App\Http\Controllers\Admin\DomainController;
use App\Http\Controllers\Admin\DomainOrderController;
use App\Http\Controllers\Admin\DomainRenewalController;
use App\Http\Controllers\Admin\EmailController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\HostedDirectAdminAccountController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\ManualPaymentController;
use App\Http\Controllers\Admin\NodeController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RegistrarController;
use App\Http\Controllers\Admin\ResellerController;
use App\Http\Controllers\Admin\ResellerPackageController;
use App\Http\Controllers\Admin\ResellerWalletController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SmsController;
use App\Http\Controllers\Admin\SmsTemplateController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Api\V1\ResellerPublic\ResellerPackageCatalogController;
use App\Http\Controllers\CurrencyPreferenceController;
use App\Http\Controllers\Customer\CheckoutController;
use App\Http\Controllers\Customer\ContainerFileController;
use App\Http\Controllers\Customer\ContainerTerminalController;
use App\Http\Controllers\Customer\DnsController;
use App\Http\Controllers\Customer\DomainSearchController;
use App\Http\Controllers\Customer\HostingPanelController;
use App\Http\Controllers\Customer\InterCustomerDomainTransferController;
use App\Http\Controllers\Customer\PaymentController;
use App\Http\Controllers\Customer\ResellerCatalogController;
use App\Http\Controllers\Customer\ServiceBrowserController;
use App\Http\Controllers\Customer\ServiceUpgradeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailWebhookController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Reseller\CartController;
use App\Http\Controllers\Reseller\CatalogController;
use App\Http\Controllers\Reseller\CustomerInvoiceController;
use App\Http\Controllers\Reseller\CustomerOrderController;
use App\Http\Controllers\Reseller\CustomerPaymentController;
use App\Http\Controllers\Reseller\DashboardActivityController;
use App\Http\Controllers\Reseller\DashboardDirectAdminController;
use App\Http\Controllers\Reseller\DeveloperController;
use App\Http\Controllers\Reseller\DomainPricingController;
use App\Http\Controllers\Reseller\DomainPushController;
use App\Http\Controllers\Reseller\ManagedServiceController;
use App\Http\Controllers\Reseller\PackageController;
use App\Http\Controllers\Reseller\ReportsController;
use App\Http\Controllers\Reseller\ServerController;
use App\Http\Controllers\Reseller\WalletController;
use App\Http\Controllers\TicketAttachmentController;
use App\Models\DomainExtension;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/currency', [CurrencyPreferenceController::class, 'update'])->name('currency.update');

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware(['auth'])->group(function () {
    // Exit impersonation (accessible by all authenticated users, including impersonated customers)
    Route::post('admin/exit-impersonation', [CustomerController::class, 'exitImpersonation'])->name('admin.exit-impersonation');
    Route::post('/exit-impersonation', function () {
        // Check if impersonating from reseller or admin
        if (session('impersonating_reseller')) {
            $resellerId = session('impersonating_reseller');
            $reseller = User::find($resellerId);
            if (! $reseller || ! $reseller->is_reseller) {
                session()->forget(['impersonating_reseller', 'impersonating_user_id']);
                auth()->logout();
                abort(403, 'Invalid impersonation session');
            }
            session()->forget(['impersonating_reseller', 'impersonating_user_id']);
            auth()->logout();
            auth()->loginUsingId($resellerId);

            return redirect()->route('reseller.customers.index')->with('success', 'Exited customer view.');
        } elseif (session('impersonating')) {
            $adminId = session('impersonating');
            $admin = User::find($adminId);
            if (! $admin || ! $admin->is_admin) {
                session()->forget(['impersonating', 'impersonating_user_id']);
                auth()->logout();
                abort(403, 'Invalid impersonation session');
            }
            session()->forget(['impersonating', 'impersonating_user_id']);
            auth()->logout();
            auth()->loginUsingId($adminId);

            return redirect()->route('admin.customers.index')->with('success', 'Exited customer view.');
        }

        return redirect()->route('dashboard');
    })->name('exit-impersonation');
});

// Public domain search and checkout (no authentication required)
Route::middleware(['throttle:60,1'])->group(function () {
    Route::get('/search-domains', [DomainSearchController::class, 'search'])->name('domains.search.public');
    Route::post('/sync-cart', [CheckoutController::class, 'syncCart'])->name('checkout.sync-cart');
    Route::get('/domain-checkout', [CheckoutController::class, 'showPublic'])->name('checkout.show.public');
    Route::post('/domain-checkout', [CheckoutController::class, 'processPublic'])
        ->middleware(['throttle:5,1', 'registration.throttle'])
        ->name('checkout.process.public');
});

// Reseller branding domain: public website API + guest checkout
Route::middleware(['reseller.host'])->group(function () {
    Route::get('/checkout', [CheckoutController::class, 'showPublic'])->name('reseller.public.checkout.show');
    Route::post('/checkout', [CheckoutController::class, 'processPublic'])
        ->middleware(['throttle:5,1', 'registration.throttle'])
        ->name('reseller.public.checkout.process');
});

Route::prefix('api/v1/public')
    ->middleware(['reseller.public.api.cors', 'reseller.public.api.tenant', 'reseller.public.api', 'throttle:reseller-public-api'])
    ->group(function () {
        Route::get('domains/search', [App\Http\Controllers\Api\V1\ResellerPublic\DomainController::class, 'search'])
            ->name('reseller.public.api.domains.search');
        Route::get('domains/extensions', [App\Http\Controllers\Api\V1\ResellerPublic\DomainController::class, 'extensions'])
            ->name('reseller.public.api.domains.extensions');
        Route::get('services', [App\Http\Controllers\Api\V1\ResellerPublic\CatalogController::class, 'index'])
            ->name('reseller.public.api.services');
        Route::get('reseller-packages', [ResellerPackageCatalogController::class, 'index'])
            ->name('reseller.public.api.reseller-packages');
        Route::post('cart', [App\Http\Controllers\Api\V1\ResellerPublic\CartController::class, 'store'])
            ->name('reseller.public.api.cart');
    });

// Payment webhooks (public, no authentication required)
Route::middleware(['throttle:120,1'])->group(function () {
    Route::post('/webhooks/c2b', [PaymentWebhookController::class, 'mpesaCallback'])->name('payment.mpesa.callback');
    Route::post('/webhooks/mpesa/callback', [PaymentWebhookController::class, 'mpesaCallback'])->name('payment.mpesa.callback.legacy');
    Route::post('/webhooks/stripe', [PaymentController::class, 'stripeWebhook'])->name('payment.stripe.webhook');
    Route::post('/webhooks/paypal', [PaymentController::class, 'paypalWebhook'])->name('payment.paypal.webhook');
    Route::post('/webhooks/email/bounce', [EmailWebhookController::class, 'bounce'])->name('webhooks.email.bounce');
});

// Legal pages (public, no authentication required)
Route::get('/terms', fn () => view('legal.terms'))->name('terms');
Route::get('/privacy', fn () => view('legal.privacy'))->name('privacy');

Route::middleware(['auth', 'skip.verification.if.impersonating'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Admin-only routes
    Route::middleware(['admin', 'admin.attention.seen'])->group(function () {
        Route::resource('admin/customers', CustomerController::class)->names('admin.customers');
        Route::post('admin/customers/{customer}/impersonate', [CustomerController::class, 'impersonate'])->name('admin.customers.impersonate');
        Route::post('admin/customers/{customer}/add-domain', [CustomerController::class, 'addDomain'])->name('admin.customers.add-domain');
        Route::post('admin/customers/{customer}/add-service', [CustomerController::class, 'addService'])->name('admin.customers.add-service');
        Route::get('admin/customers/{customer}/generate-username', [CustomerController::class, 'generateUsername'])->name('admin.customers.generate-username');
        Route::get('admin/generate-password', [CustomerController::class, 'generatePassword'])->name('admin.customers.generate-password');
        Route::post('admin/customers/{customer}/invoice', [CustomerController::class, 'createInvoice'])->name('admin.customers.create-invoice');
        Route::post('admin/customers/{customer}/convert-to-reseller', [CustomerController::class, 'convertToReseller'])->name('admin.customers.convert-to-reseller');
        Route::get('admin/customers/{customer}/transfer-preview', [CustomerController::class, 'transferPreview'])->name('admin.customers.transfer-preview');
        Route::post('admin/customers/{customer}/transfer-to-reseller', [CustomerController::class, 'transferToReseller'])->name('admin.customers.transfer-to-reseller');
        Route::post('admin/customers/{customer}/credits', [CustomerController::class, 'addCredit'])->name('admin.customers.add-credit');
        Route::post('admin/customers/{customer}/credits/remove', [CustomerController::class, 'removeCredit'])->name('admin.customers.remove-credit');
        Route::post('admin/customers/{customer}/credits/{credit}/revoke', [CustomerController::class, 'revokeCredit'])->name('admin.customers.revoke-credit');
        Route::post('admin/directadmin-accounts/link', [HostedDirectAdminAccountController::class, 'link'])->name('admin.directadmin-accounts.link');
        Route::post('admin/services/{service}/connect-billing', [HostedDirectAdminAccountController::class, 'connectBilling'])->name('admin.directadmin-accounts.connect-billing');
        Route::post('admin/products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('admin.products.duplicate');
        Route::resource('admin/products', ProductController::class)->names('admin.products');
        Route::resource('admin/invoices', InvoiceController::class)->names('admin.invoices');
        Route::get('admin/invoices/{invoice}/download', [InvoiceController::class, 'download'])->name('admin.invoices.download');
        Route::post('admin/invoices/{invoice}/payments', [InvoiceController::class, 'addPayment'])->name('admin.invoices.add-payment');
        Route::post('admin/invoices/{invoice}/mark-paid', [InvoiceController::class, 'markAsPaid'])->name('admin.invoices.mark-paid');
        Route::resource('admin/payments', App\Http\Controllers\Admin\PaymentController::class)->names('admin.payments');
        Route::resource('admin/credits', CreditController::class)->except(['edit', 'update'])->names('admin.credits');
        Route::post('admin/credits/{credit}/apply', [CreditController::class, 'apply'])->name('admin.credits.apply');
        Route::post('admin/credits/{credit}/remove', [CreditController::class, 'remove'])->name('admin.credits.remove');
        Route::get('admin/customers/{user}/credits-report', [CreditController::class, 'customerReport'])->name('admin.credits.customer-report');
        Route::get('admin/reports', [AdminReportsController::class, 'index'])->name('admin.reports.index');
        Route::get('admin/activity-logs', [AdminActivityLogController::class, 'index'])->name('admin.activity-logs.index');
        Route::post('admin/payments/{payment}/approve-manual', [App\Http\Controllers\Admin\PaymentController::class, 'approveManual'])->name('admin.payments.approve-manual');
        Route::post('admin/payments/{payment}/reject-manual', [App\Http\Controllers\Admin\PaymentController::class, 'rejectManual'])->name('admin.payments.reject-manual');
        Route::resource('admin/services', ServiceController::class)->names('admin.services');
        Route::get('admin/services/create', [ServiceController::class, 'create'])->name('admin.services.create');
        Route::post('admin/services', [ServiceController::class, 'store'])->name('admin.services.store');
        // DirectAdmin cross-node package consistency (must be registered BEFORE the
        // nodes resource route so /admin/nodes/{node} doesn't swallow the URL).
        Route::get('admin/shared-hosting/package-consistency', [NodeController::class, 'packageConsistency'])->name('admin.shared-hosting.package-consistency');
        Route::post('admin/direct-admin-packages/{package}/push-limits', [NodeController::class, 'pushPackageLimits'])->name('admin.direct-admin-packages.push-limits');
        Route::resource('admin/nodes', NodeController::class)->names('admin.nodes');
        Route::post('admin/nodes/{node}/status', [NodeController::class, 'updateStatus'])->name('admin.nodes.update-status');
        Route::post('admin/nodes/{node}/test-connection', [NodeController::class, 'testConnection'])->name('admin.nodes.test-connection');
        Route::post('admin/nodes/{node}/test-health', [NodeController::class, 'testHealth'])->name('admin.nodes.test-health');
        Route::post('admin/nodes/{node}/utilization', [NodeController::class, 'updateUtilization'])->name('admin.nodes.update-utilization');
        Route::post('admin/nodes/{node}/heartbeat', [NodeController::class, 'heartbeat'])->name('admin.nodes.heartbeat');
        Route::post('admin/nodes/{node}/sync-packages', [NodeController::class, 'syncDirectAdminPackages'])->name('admin.nodes.sync-packages');
        Route::get('admin/nodes/{node}/packages-json', [NodeController::class, 'packagesJson'])->name('admin.nodes.packages-json');
        Route::get('admin/nodes-status-json', [NodeController::class, 'statusJson'])->name('admin.nodes.status-json');
        Route::delete('admin/nodes/{node}', [NodeController::class, 'delete'])->name('admin.nodes.delete');
        Route::resource('admin/domains', DomainController::class)->names('admin.domains');
        Route::post('admin/domains/{domain}/generate-invoice', [DomainController::class, 'generateInvoice'])->name('admin.domains.generate-invoice');
        Route::get('admin/domains-pricing', [DomainController::class, 'pricing'])->name('admin.domains.pricing');
        Route::post('admin/domains-pricing', [DomainController::class, 'storePricing'])->name('admin.domains.pricing.store');
        Route::post('admin/domain-extensions', [DomainController::class, 'storeExtension'])->name('admin.domain-extensions.store');
        Route::resource('admin/reseller-packages', ResellerPackageController::class)->names('admin.reseller-packages');
        Route::resource('admin/orders', OrderController::class)->only(['index', 'show', 'destroy'])->names('admin.orders');
        Route::post('admin/orders/{order}/mark-complete', [OrderController::class, 'markComplete'])->name('admin.orders.mark-complete');
        Route::get('admin/resellers', [ResellerController::class, 'index'])->name('admin.resellers.index');
        Route::get('admin/resellers/create', [ResellerController::class, 'create'])->name('admin.resellers.create');
        Route::post('admin/resellers', [ResellerController::class, 'store'])->name('admin.resellers.store');
        Route::get('admin/resellers/{user}/edit', [ResellerController::class, 'edit'])->name('admin.resellers.edit');
        Route::put('admin/resellers/{user}', [ResellerController::class, 'update'])->name('admin.resellers.update');
        Route::get('admin/resellers/{user}', [ResellerController::class, 'show'])->name('admin.resellers.show');
        Route::post('admin/resellers/{user}/promote', [ResellerController::class, 'promote'])->name('admin.resellers.promote');
        Route::post('admin/resellers/{user}/demote', [ResellerController::class, 'demote'])->name('admin.resellers.demote');
        Route::post('admin/resellers/{user}/assign-package', [ResellerController::class, 'assignPackage'])->name('admin.resellers.assign-package');
        Route::post('admin/resellers/{user}/upgrade-package', [ResellerController::class, 'upgradePackage'])->name('admin.resellers.upgrade-package');
        Route::post('admin/resellers/{user}/update-billing', [ResellerController::class, 'updateBilling'])->name('admin.resellers.update-billing');
        Route::post('admin/resellers/{user}/generate-renewal-invoice', [ResellerController::class, 'generateRenewalInvoice'])->name('admin.resellers.generate-renewal-invoice');
        Route::post('admin/resellers/{user}/impersonate', [ResellerController::class, 'impersonate'])->name('admin.resellers.impersonate');
        Route::post('admin/resellers/{user}/add-domain', [ResellerController::class, 'addDomain'])->name('admin.resellers.add-domain');
        Route::post('admin/resellers/{user}/add-service', [ResellerController::class, 'addService'])->name('admin.resellers.add-service');
        Route::post('admin/resellers/{user}/wallet-adjust', [ResellerController::class, 'adjustWallet'])->name('admin.resellers.wallet-adjust');
        Route::post('admin/resellers/{user}/enforce-suspension', [ResellerController::class, 'enforceSuspension'])->name('admin.resellers.enforce-suspension');
        Route::post('admin/resellers/{user}/directadmin/test', [ResellerController::class, 'testDirectAdminBinding'])->name('admin.resellers.directadmin.test');
        Route::post('admin/resellers/{user}/directadmin/connect', [ResellerController::class, 'connectDirectAdmin'])->name('admin.resellers.directadmin.connect');
        Route::post('admin/resellers/{user}/directadmin/disconnect', [ResellerController::class, 'disconnectDirectAdmin'])->name('admin.resellers.directadmin.disconnect');
        Route::get('admin/resellers/{user}/directadmin/panel-login', [ResellerController::class, 'directAdminPanelLogin'])->middleware('throttle:10,1')->name('admin.resellers.directadmin.panel-login');
        Route::get('admin/settings', [SettingController::class, 'index'])->name('admin.settings.index');
        Route::post('admin/settings', [SettingController::class, 'update'])->name('admin.settings.update');
        Route::post('admin/settings/node-nameservers', [SettingController::class, 'updateDirectAdminNameservers'])->name('admin.settings.update-node-nameservers');
        Route::post('admin/settings/upload-file', [SettingController::class, 'uploadFile'])->name('admin.settings.upload-file');
        Route::post('admin/settings/test-smtp', [SettingController::class, 'testSmtp'])->name('admin.settings.test-smtp');
        Route::post('admin/settings/test-telegram', [SettingController::class, 'testTelegram'])->name('admin.settings.test-telegram');
        Route::post('admin/settings/test-sms', [SettingController::class, 'testSms'])->name('admin.settings.test-sms');
        Route::post('admin/settings/paypal/connect', [SettingController::class, 'startPayPalConnect'])->name('admin.settings.paypal.connect');
        Route::get('admin/settings/paypal/connect/callback', [SettingController::class, 'payPalConnectCallback'])->name('admin.settings.paypal.connect.callback');
        Route::post('admin/settings/paypal/disconnect', [SettingController::class, 'disconnectPayPal'])->name('admin.settings.paypal.disconnect');
        Route::post('admin/settings/paypal/refresh', [SettingController::class, 'refreshPayPalConnection'])->name('admin.settings.paypal.refresh');
        Route::post('admin/settings/test-cloudflare', [SettingController::class, 'testCloudflare'])->name('admin.settings.test-cloudflare');
        Route::post('admin/settings/test-mpesa', [SettingController::class, 'testMpesa'])->name('admin.settings.test-mpesa');
        Route::post('admin/settings/test-paypal', [SettingController::class, 'testPayPal'])->name('admin.settings.test-paypal');
        Route::post('admin/settings/register-mpesa-urls', [SettingController::class, 'registerMpesaUrls'])->name('admin.settings.register-mpesa-urls');
        Route::post('admin/settings/simulate-mpesa-payment', [SettingController::class, 'simulateMpesaPayment'])->name('admin.settings.simulate-mpesa-payment');
        Route::post('admin/settings/debug-log', [SettingController::class, 'debugLog'])->name('admin.settings.debug-log');
        Route::post('admin/settings/refresh-currencies', [SettingController::class, 'refreshCurrencies'])->name('admin.settings.refresh-currencies');

        Route::get('admin/developers', [App\Http\Controllers\Admin\DeveloperController::class, 'index'])->name('admin.developers.index');
        Route::post('admin/developers/settings', [App\Http\Controllers\Admin\DeveloperController::class, 'updateSettings'])->name('admin.developers.settings.update');
        Route::post('admin/developers/token', [App\Http\Controllers\Admin\DeveloperController::class, 'regenerateToken'])->name('admin.developers.token.regenerate');
        Route::post('admin/developers/token/reveal', [App\Http\Controllers\Admin\DeveloperController::class, 'revealToken'])->name('admin.developers.token.reveal');

        Route::post('admin/registrars', [RegistrarController::class, 'store'])->name('admin.registrars.store');
        Route::put('admin/registrars/{registrar}', [RegistrarController::class, 'update'])->name('admin.registrars.update');
        Route::delete('admin/registrars/{registrar}', [RegistrarController::class, 'destroy'])->name('admin.registrars.destroy');
        Route::post('admin/registrars/{registrar}/test', [RegistrarController::class, 'test'])->name('admin.registrars.test');

        // Manual Payment Settings
        Route::get('admin/manual-payment', [ManualPaymentController::class, 'index'])->name('admin.manual-payment.index');
        Route::post('admin/manual-payment', [ManualPaymentController::class, 'update'])->name('admin.manual-payment.update');

        // Admin Profile
        Route::get('admin/profile', [App\Http\Controllers\Admin\ProfileController::class, 'edit'])->name('admin.profile.edit');
        Route::patch('admin/profile', [App\Http\Controllers\Admin\ProfileController::class, 'update'])->name('admin.profile.update');
        Route::post('admin/profile/two-factor/enable', [App\Http\Controllers\Admin\ProfileController::class, 'enableTwoFactor'])->name('admin.profile.two-factor.enable');
        Route::post('admin/profile/two-factor/disable', [App\Http\Controllers\Admin\ProfileController::class, 'disableTwoFactor'])->name('admin.profile.two-factor.disable');
        Route::post('admin/profile/two-factor/regenerate-codes', [App\Http\Controllers\Admin\ProfileController::class, 'regenerateRecoveryCodes'])->name('admin.profile.two-factor.regenerate-codes');

        // Currency Management
        Route::get('admin/currencies', [CurrencyController::class, 'index'])->name('admin.currencies.index');
        Route::post('admin/currencies', [CurrencyController::class, 'store'])->name('admin.currencies.store');
        Route::patch('admin/currencies/{currency}', [CurrencyController::class, 'update'])->name('admin.currencies.update');
        Route::delete('admin/currencies/{currency}', [CurrencyController::class, 'destroy'])->name('admin.currencies.destroy');
        Route::post('admin/currencies/refresh', [CurrencyController::class, 'refreshRates'])->name('admin.currencies.refresh');
        Route::post('admin/currencies/test-conversion', [CurrencyController::class, 'testConversion'])->name('admin.currencies.test-conversion');

        // SMS Notifications
        Route::get('admin/sms', [SmsController::class, 'index'])->name('admin.sms.index');
        Route::post('admin/sms/send', [SmsController::class, 'send'])->name('admin.sms.send');

        // SMS Templates (AJAX)
        Route::put('admin/sms-templates/{smsTemplate}', [SmsTemplateController::class, 'update'])->name('admin.sms-templates.update');
        Route::post('admin/sms-templates/{smsTemplate}/reset', [SmsTemplateController::class, 'reset'])->name('admin.sms-templates.reset');

        Route::put('admin/email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])->name('admin.email-templates.update');
        Route::post('admin/email-templates/{emailTemplate}/reset', [EmailTemplateController::class, 'reset'])->name('admin.email-templates.reset');

        // Email Logs
        Route::get('admin/emails', [EmailController::class, 'index'])->name('admin.emails.index');
        Route::get('admin/emails/{email}', [EmailController::class, 'show'])->name('admin.emails.show');
        Route::post('admin/emails/{email}/resend', [EmailController::class, 'resend'])->name('admin.emails.resend');

        // Cron Jobs
        Route::get('admin/cron', [CronController::class, 'index'])->name('admin.cron.index');
        Route::get('admin/cron/chart', [CronController::class, 'chart'])->name('admin.cron.chart');
        Route::get('admin/cron/{job}', [CronController::class, 'show'])->name('admin.cron.show');
        Route::post('admin/cron/{job}/run', [CronController::class, 'run'])->name('admin.cron.run');
        Route::post('admin/cron/{job}/toggle', [CronController::class, 'toggle'])->name('admin.cron.toggle');
        Route::get('admin/cron/{job}/logs', [CronController::class, 'logs'])->name('admin.cron.logs');

        // Service actions
        Route::post('admin/services/{service}/provision', [ServiceController::class, 'provision'])->name('admin.services.provision');
        Route::post('admin/services/{service}/suspend', [ServiceController::class, 'suspend'])->name('admin.services.suspend');
        Route::post('admin/services/{service}/unsuspend', [ServiceController::class, 'unsuspend'])->name('admin.services.unsuspend');
        Route::post('admin/services/{service}/terminate', [ServiceController::class, 'terminate'])->name('admin.services.terminate');
        Route::post('admin/services/{service}/cancel', [ServiceController::class, 'cancel'])->name('admin.services.cancel');
        Route::post('admin/services/refresh-live-status', [ServiceController::class, 'refreshLiveStatusBulk'])->name('admin.services.refresh-live-status');
        Route::post('admin/services/{service}/refresh-status', [ServiceController::class, 'refreshStatus'])->name('admin.services.refresh-status');
        Route::post('admin/services/{service}/test-directadmin', [ServiceController::class, 'testDirectAdminConnection'])->name('admin.services.test-directadmin');
        Route::post('admin/services/{service}/resend-credentials', [ServiceController::class, 'resendCredentials'])->name('admin.services.resend-credentials');
        Route::get('admin/services/{service}/transfer-preview', [ServiceController::class, 'transferPreview'])->name('admin.services.transfer-preview');
        Route::post('admin/services/{service}/transfer', [ServiceController::class, 'transfer'])->name('admin.services.transfer');
        Route::post('admin/services/{service}/upgrade-hosting', [ServiceController::class, 'upgradeHosting'])->name('admin.services.upgrade-hosting');
        Route::post('admin/services/{service}/reconcile-hosting', [ServiceController::class, 'reconcileHostingPackage'])->name('admin.services.reconcile-hosting');

        // Container management
        Route::resource('admin/container-templates', ContainerTemplateController::class)->names('admin.container-templates');
        Route::resource('admin/database-templates', DatabaseTemplateController::class)->except(['show'])->names('admin.database-templates');
        Route::post('admin/services/{service}/container/restart', [ContainerController::class, 'restart'])->name('admin.services.container.restart');
        Route::post('admin/services/{service}/container/suspend', [ContainerController::class, 'suspend'])->name('admin.services.container.suspend');
        Route::post('admin/services/{service}/container/stop', [ContainerController::class, 'stop'])->name('admin.services.container.stop');
        Route::post('admin/services/{service}/container/start', [ContainerController::class, 'start'])->name('admin.services.container.start');
        Route::get('admin/services/{service}/container/logs', [ContainerController::class, 'logs'])->name('admin.services.container.logs');
        Route::get('admin/services/{service}/container/metrics', [ContainerController::class, 'metrics'])->name('admin.services.container.metrics');
        Route::post('admin/services/{service}/container/redeploy', [ContainerController::class, 'redeploy'])->name('admin.services.container.redeploy');
        Route::get('admin/services/{service}/container/edit', [ContainerController::class, 'edit'])->name('admin.services.container.edit');
        Route::patch('admin/services/{service}/container', [ContainerController::class, 'update'])->name('admin.services.container.update');
        Route::post('admin/services/{service}/container/provision', [ContainerController::class, 'provision'])->name('admin.services.container.provision');
        Route::post('admin/services/{service}/container/domains', [ContainerController::class, 'bindDomain'])->name('admin.services.container.domains.bind');
        Route::delete('admin/services/{service}/container/domains/{domain}', [ContainerController::class, 'unbindDomain'])->name('admin.services.container.domains.unbind');
        Route::post('admin/services/{service}/container/domains/{domain}/ssl', [ContainerController::class, 'enableSsl'])->name('admin.services.container.domains.ssl');

        // Container backups
        Route::post('admin/services/{service}/container/backups', [ContainerController::class, 'createBackup'])->name('admin.services.container.backups.create');
        Route::post('admin/services/{service}/container/backups/{backup}/restore', [ContainerController::class, 'restoreBackup'])->name('admin.services.container.backups.restore');
        Route::delete('admin/services/{service}/container/backups/{backup}', [ContainerController::class, 'deleteBackup'])->name('admin.services.container.backups.delete');

        // Container migration
        Route::get('admin/services/{service}/container/migrate', [ContainerMigrationController::class, 'index'])->name('admin.services.container.migrate');
        Route::post('admin/services/{service}/container/migrate', [ContainerMigrationController::class, 'migrate'])->name('admin.services.container.migrate.confirm');
        Route::post('admin/nodes/{node}/migrate-containers', [ContainerMigrationController::class, 'migrateNode'])->name('admin.nodes.migrate-containers');

        // Admin Ticket Management
        Route::resource('admin/tickets', TicketController::class)->names('tickets');
        Route::post('admin/tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
        Route::patch('admin/tickets/{ticket}/status', [TicketController::class, 'updateStatus'])->name('tickets.updateStatus');
        Route::patch('admin/tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('tickets.assign');
        Route::get('admin/tickets/{ticket}/attachments/{attachment}', [TicketAttachmentController::class, 'show'])->name('tickets.attachments.show');

        // Reseller Wallet Management
        Route::get('admin/reseller-wallets', [ResellerWalletController::class, 'index'])->name('admin.reseller-wallets.index');
        Route::get('admin/reseller-wallets/{reseller}', [ResellerWalletController::class, 'show'])->name('admin.reseller-wallets.show');
        Route::post('admin/reseller-wallets/{reseller}/adjust', [ResellerWalletController::class, 'adjust'])->name('admin.reseller-wallets.adjust');
        Route::get('admin/reseller-wallets/{reseller}/export', [ResellerWalletController::class, 'exportPdf'])->name('admin.reseller-wallets.export');

        // Domain Orders Management
        Route::get('admin/domain-orders', [DomainOrderController::class, 'index'])->name('admin.domain-orders.index');
        Route::get('admin/domain-orders/{order}', [DomainOrderController::class, 'show'])->name('admin.domain-orders.show');
        Route::post('admin/domain-orders/{order}/complete', [DomainOrderController::class, 'complete'])->name('admin.domain-orders.complete');
        Route::post('admin/domain-orders/{order}/fail', [DomainOrderController::class, 'fail'])->name('admin.domain-orders.fail');
        Route::post('admin/domain-orders/{order}/push', [DomainOrderController::class, 'push'])->name('admin.domain-orders.push');
        Route::post('admin/domain-orders/{order}/push-registrar', [DomainOrderController::class, 'pushToRegistrar'])->name('admin.domain-orders.push-registrar');
        Route::patch('admin/domain-orders/{order}/transfer-details', [DomainOrderController::class, 'updateTransferDetails'])->name('admin.domain-orders.transfer-details.update');
        Route::post('admin/domain-orders/{order}/cancel', [DomainOrderController::class, 'cancel'])->name('admin.domain-orders.cancel');
        Route::delete('admin/domain-orders/{order}', [DomainOrderController::class, 'destroy'])->name('admin.domain-orders.destroy');

        // Domain renewals
        Route::get('admin/domain-renewals', [DomainRenewalController::class, 'index'])->name('admin.domain-renewals.index');
        Route::get('admin/domain-renewals/{renewal}', [DomainRenewalController::class, 'show'])->name('admin.domain-renewals.show');
        Route::post('admin/domain-renewals/{renewal}/complete', [DomainRenewalController::class, 'complete'])->name('admin.domain-renewals.complete');
        Route::post('admin/domain-renewals/{renewal}/complete-manually', [DomainRenewalController::class, 'completeManually'])->name('admin.domain-renewals.complete-manually');
        Route::post('admin/domain-renewals/{renewal}/fail', [DomainRenewalController::class, 'fail'])->name('admin.domain-renewals.fail');
        Route::post('admin/domain-renewals/{renewal}/expire', [DomainRenewalController::class, 'expire'])->name('admin.domain-renewals.expire');
    });

    // Reseller-only routes
    Route::middleware(['reseller', 'reseller.billing'])->group(function () {
        Route::get('reseller/dashboard/directadmin-live', [DashboardDirectAdminController::class, 'live'])
            ->middleware('throttle:30,1')
            ->name('reseller.dashboard.directadmin-live');

        Route::get('reseller/dashboard/directadmin-panel', [DashboardDirectAdminController::class, 'panel'])
            ->middleware('throttle:20,1')
            ->name('reseller.dashboard.directadmin-panel');

        Route::get('reseller/dashboard/activity', DashboardActivityController::class)
            ->middleware('throttle:60,1')
            ->name('reseller.dashboard.activity');

        Route::resource('reseller/tickets', App\Http\Controllers\Reseller\TicketController::class)
            ->only(['index', 'show', 'create', 'store'])
            ->names('reseller.tickets');
        Route::post('reseller/tickets/{ticket}/reply', [App\Http\Controllers\Reseller\TicketController::class, 'reply'])->name('reseller.tickets.reply');
        Route::post('reseller/tickets/{ticket}/escalate', [App\Http\Controllers\Reseller\TicketController::class, 'escalate'])->name('reseller.tickets.escalate');
        Route::patch('reseller/tickets/{ticket}/close', [App\Http\Controllers\Reseller\TicketController::class, 'close'])->name('reseller.tickets.close');
        Route::get('reseller/tickets/{ticket}/attachments/{attachment}', [TicketAttachmentController::class, 'show'])->name('reseller.tickets.attachments.show');

        Route::get('reseller/services', [ManagedServiceController::class, 'index'])->name('reseller.services.index');
        Route::get('reseller/services/{service}', [ManagedServiceController::class, 'show'])->name('reseller.services.show');
        Route::patch('reseller/services/{service}', [ManagedServiceController::class, 'update'])->name('reseller.services.update');
        Route::post('reseller/services/{service}/suspend', [ManagedServiceController::class, 'suspend'])->name('reseller.services.suspend');
        Route::post('reseller/services/{service}/unsuspend', [ManagedServiceController::class, 'unsuspend'])->name('reseller.services.unsuspend');
        Route::post('reseller/services/{service}/terminate', [ManagedServiceController::class, 'terminate'])->name('reseller.services.terminate');
        Route::delete('reseller/services/{service}', [ManagedServiceController::class, 'destroy'])->name('reseller.services.destroy');

        Route::get('reseller/reports', [ReportsController::class, 'index'])->name('reseller.reports.index');
        Route::get('reseller/reports/export/customers', [ReportsController::class, 'exportCustomers'])->name('reseller.reports.export.customers');
        Route::get('reseller/reports/export/invoices', [ReportsController::class, 'exportInvoices'])->name('reseller.reports.export.invoices');
        Route::get('reseller/reports/export/revenue', [ReportsController::class, 'exportRevenue'])->name('reseller.reports.export.revenue');
        Route::get('reseller/reports/export/services', [ReportsController::class, 'exportServices'])->name('reseller.reports.export.services');
        Route::get('reseller/reports/export/margins', [ReportsController::class, 'exportMargins'])->name('reseller.reports.export.margins');

        Route::get('reseller/customer-invoices', [CustomerInvoiceController::class, 'index'])->name('reseller.customer-invoices.index');
        Route::get('reseller/customer-invoices/create', [CustomerInvoiceController::class, 'create'])->name('reseller.customer-invoices.create');
        Route::post('reseller/customer-invoices', [CustomerInvoiceController::class, 'store'])->name('reseller.customer-invoices.store');
        Route::get('reseller/customer-invoices/{invoice}/edit', [CustomerInvoiceController::class, 'edit'])->name('reseller.customer-invoices.edit');
        Route::put('reseller/customer-invoices/{invoice}', [CustomerInvoiceController::class, 'update'])->name('reseller.customer-invoices.update');
        Route::get('reseller/customer-invoices/{invoice}', [CustomerInvoiceController::class, 'show'])->name('reseller.customer-invoices.show');
        Route::post('reseller/customer-invoices/{invoice}/payments', [CustomerInvoiceController::class, 'addPayment'])->name('reseller.customer-invoices.add-payment');
        Route::post('reseller/customer-invoices/{invoice}/mark-paid', [CustomerInvoiceController::class, 'markPaid'])->name('reseller.customer-invoices.mark-paid');
        Route::post('reseller/customer-invoices/{invoice}/cancel', [CustomerInvoiceController::class, 'cancel'])->name('reseller.customer-invoices.cancel');
        Route::post('reseller/customer-invoices/{invoice}/resend', [CustomerInvoiceController::class, 'resend'])->name('reseller.customer-invoices.resend');
        Route::get('reseller/customer-invoices/{invoice}/download', [CustomerInvoiceController::class, 'download'])->name('reseller.customer-invoices.download');

        Route::get('reseller/customer-payments', [CustomerPaymentController::class, 'index'])->name('reseller.customer-payments.index');

        Route::get('reseller/customer-orders/hosting', function (Request $request) {
            $params = $request->filled('customer') ? ['customer' => $request->integer('customer')] : [];

            return redirect()->route('reseller.customer-invoices.create', $params);
        })->name('reseller.customer-orders.hosting.create');
        Route::post('reseller/customer-orders/hosting', [CustomerOrderController::class, 'storeHosting'])->name('reseller.customer-orders.hosting.store');
        Route::get('reseller/customer-orders/domain', function (Request $request) {
            $params = $request->filled('customer') ? ['customer' => $request->integer('customer')] : [];

            return redirect()->route('reseller.domains.index', $params);
        })->name('reseller.customer-orders.domain.create');
        Route::post('reseller/customer-orders/domain', [CustomerOrderController::class, 'storeDomain'])->name('reseller.customer-orders.domain.store');
        Route::get('reseller/customer-orders/create', function (Request $request) {
            $params = $request->filled('customer') ? ['customer' => $request->integer('customer')] : [];

            return redirect()->route('reseller.customer-invoices.create', $params);
        })->name('reseller.customer-orders.create');
        Route::post('reseller/customer-orders', [CustomerOrderController::class, 'store'])->name('reseller.customer-orders.store');

        Route::get('my/packages', [PackageController::class, 'index'])->name('reseller.packages.index');
        Route::post('my/packages/renew', [PackageController::class, 'renew'])->name('reseller.packages.renew');
        Route::post('my/packages/{package}/subscribe', [PackageController::class, 'subscribe'])->name('reseller.packages.subscribe');

        Route::get('reseller/settings', [App\Http\Controllers\Reseller\SettingController::class, 'index'])->name('reseller.settings.index');
        Route::post('reseller/settings/mpesa', [App\Http\Controllers\Reseller\SettingController::class, 'updateMpesa'])->name('reseller.settings.mpesa.update');
        Route::post('reseller/settings/mpesa/register-urls', [App\Http\Controllers\Reseller\SettingController::class, 'registerMpesaUrls'])->name('reseller.settings.mpesa.register-urls');
        Route::post('reseller/settings/sms', [App\Http\Controllers\Reseller\SettingController::class, 'updateSms'])->name('reseller.settings.sms.update');
        Route::post('reseller/settings/sms/test', [App\Http\Controllers\Reseller\SettingController::class, 'testSms'])->name('reseller.settings.sms.test');
        Route::post('reseller/settings/smtp', [App\Http\Controllers\Reseller\SettingController::class, 'updateSmtp'])->name('reseller.settings.smtp.update');
        Route::post('reseller/settings/smtp/test', [App\Http\Controllers\Reseller\SettingController::class, 'testSmtp'])->name('reseller.settings.smtp.test');
        Route::post('reseller/settings/branding', [App\Http\Controllers\Reseller\SettingController::class, 'updateBranding'])->name('reseller.settings.branding.update');
        Route::post('reseller/settings/nameservers', [App\Http\Controllers\Reseller\SettingController::class, 'updateNameservers'])->name('reseller.settings.nameservers.update');
        Route::post('reseller/settings/branding/upload', [App\Http\Controllers\Reseller\SettingController::class, 'uploadBrandingFile'])->name('reseller.settings.branding.upload');
        Route::delete('reseller/settings/branding/file', [App\Http\Controllers\Reseller\SettingController::class, 'deleteBrandingFile'])->name('reseller.settings.branding.delete');
        Route::get('reseller/settings/branding/ssl/check-dns', [App\Http\Controllers\Reseller\SettingController::class, 'checkSslDns'])->name('reseller.settings.branding.ssl.check-dns');
        Route::post('reseller/settings/branding/ssl/issue', [App\Http\Controllers\Reseller\SettingController::class, 'issueSsl'])->name('reseller.settings.branding.ssl.issue');
        Route::post('reseller/settings/branding/ssl/provision', [App\Http\Controllers\Reseller\SettingController::class, 'provisionSsl'])->name('reseller.settings.branding.ssl.provision');
        Route::post('reseller/settings/branding/ssl/renew', [App\Http\Controllers\Reseller\SettingController::class, 'renewSsl'])->name('reseller.settings.branding.ssl.renew');

        Route::get('reseller/developers', [DeveloperController::class, 'index'])->name('reseller.developers.index');
        Route::post('reseller/developers/token', [DeveloperController::class, 'regenerateToken'])->name('reseller.developers.token.regenerate');
        Route::post('reseller/developers/token/reveal', [DeveloperController::class, 'revealToken'])->name('reseller.developers.token.reveal');

        Route::resource('reseller/invoices', App\Http\Controllers\Reseller\InvoiceController::class)->only(['index', 'show'])->names('reseller.invoices');
        Route::get('reseller/invoices/{invoice}/download', [App\Http\Controllers\Reseller\InvoiceController::class, 'download'])->name('reseller.invoices.download');

        Route::get('reseller/wallet', [WalletController::class, 'index'])->name('reseller.wallet.index');
        Route::post('reseller/wallet/topup', [WalletController::class, 'initiateTopup'])->name('reseller.wallet.topup');
        Route::get('reseller/wallet/topup/status/{invoice}', [WalletController::class, 'checkTopupStatus'])->name('reseller.wallet.topup.status');
        Route::get('reseller/wallet/transactions', [WalletController::class, 'transactions'])->name('reseller.wallet.transactions');
        Route::get('reseller/wallet/export', [WalletController::class, 'exportPdf'])->name('reseller.wallet.export');

        Route::middleware('reseller.limits')->group(function () {
            Route::resource('reseller/customers', App\Http\Controllers\Reseller\CustomerController::class)->names('reseller.customers');
            Route::post('reseller/customers/{customer}/impersonate', [App\Http\Controllers\Reseller\CustomerController::class, 'impersonate'])->name('reseller.customers.impersonate');
            Route::post('reseller/directadmin-accounts/link', [App\Http\Controllers\Reseller\HostedDirectAdminAccountController::class, 'link'])->name('reseller.directadmin-accounts.link');
            Route::post('reseller/directadmin-accounts/bulk-link', [App\Http\Controllers\Reseller\HostedDirectAdminAccountController::class, 'bulkLink'])->name('reseller.directadmin-accounts.bulk-link');
            Route::post('reseller/services/{service}/connect-billing', [App\Http\Controllers\Reseller\HostedDirectAdminAccountController::class, 'connectBilling'])->name('reseller.directadmin-accounts.connect-billing');
            Route::get('reseller/directadmin-accounts/catalog-options', [App\Http\Controllers\Reseller\HostedDirectAdminAccountController::class, 'catalogOptions'])->name('reseller.directadmin-accounts.catalog-options');
            Route::post('reseller/exit-impersonation', [App\Http\Controllers\Reseller\CustomerController::class, 'exitImpersonation'])->name('reseller.exit-impersonation');
            Route::resource('reseller/catalog', CatalogController::class)
                ->parameters(['catalog' => 'catalogItem'])
                ->names('reseller.catalog');
            Route::get('reseller/domains', [App\Http\Controllers\Reseller\DomainController::class, 'index'])->name('reseller.domains.index');
            Route::get('reseller/domains/{domain}', [App\Http\Controllers\Reseller\DomainController::class, 'show'])->name('reseller.domains.show');
            Route::put('reseller/domains/{domain}/nameservers', [App\Http\Controllers\Reseller\DomainController::class, 'updateNameservers'])->name('reseller.domains.nameservers');
            Route::post('reseller/domains/{domain}/transfer', [App\Http\Controllers\Reseller\DomainController::class, 'initiateTransfer'])->name('reseller.domains.transfer');
            Route::post('reseller/domains/{domain}/renew', [App\Http\Controllers\Reseller\DomainController::class, 'addRenewalToCart'])->name('reseller.domains.renew');
            Route::delete('reseller/domains/{domain}', [App\Http\Controllers\Reseller\DomainController::class, 'destroy'])->name('reseller.domains.destroy');
            Route::get('reseller/domains-pricing', [DomainPricingController::class, 'index'])->name('reseller.domains.pricing');
            Route::post('reseller/domains-pricing', [DomainPricingController::class, 'update'])->name('reseller.domains.pricing.update');
            Route::get('api/reseller/domains/pricing/{extension}', [App\Http\Controllers\Reseller\DomainController::class, 'getPricing'])->name('reseller.domains.pricing.api');
            Route::get('api/reseller/domains/check', [App\Http\Controllers\Reseller\DomainController::class, 'checkAvailability'])->name('reseller.domains.check');
            Route::get('reseller/domain-orders', [DomainPushController::class, 'index'])->name('reseller.domain-orders.index');
            Route::post('reseller/domain-orders/{order}/push', [DomainPushController::class, 'push'])->name('reseller.domain-orders.push');
            Route::post('reseller/domain-orders/{order}/retry', [DomainPushController::class, 'retry'])->name('reseller.domain-orders.retry');
            Route::post('reseller/domain-orders/{order}/cancel', [DomainPushController::class, 'cancel'])->name('reseller.domain-orders.cancel');
            Route::delete('reseller/domain-orders/{order}', [DomainPushController::class, 'destroy'])->name('reseller.domain-orders.destroy');
            Route::get('reseller/servers', [ServerController::class, 'index'])->name('reseller.servers.index');
            Route::post('reseller/servers/order', [ServerController::class, 'order'])->name('reseller.servers.order');
            Route::get('reseller/cart', [CartController::class, 'index'])->name('reseller.cart.index');
            Route::post('reseller/cart/context', [CartController::class, 'setContext'])->name('reseller.cart.context');
            Route::post('reseller/cart/add', [CartController::class, 'add'])->name('reseller.cart.add');
            Route::post('reseller/cart/transfer', [CartController::class, 'addTransfer'])->name('reseller.cart.transfer');
            Route::post('reseller/cart/{key}/nameservers', [CartController::class, 'updateNameservers'])->name('reseller.cart.nameservers');
            Route::delete('reseller/cart/{key}', [CartController::class, 'remove'])->name('reseller.cart.remove');
            Route::post('reseller/cart/clear', [CartController::class, 'clear'])->name('reseller.cart.clear');
            Route::get('reseller/checkout', [App\Http\Controllers\Reseller\CheckoutController::class, 'show'])->name('reseller.checkout.show');
            Route::post('reseller/checkout', [App\Http\Controllers\Reseller\CheckoutController::class, 'process'])->name('reseller.checkout.process');
        });

        Route::get('reseller/invoices/{invoice}/pay', [App\Http\Controllers\Reseller\PaymentController::class, 'selectMethod'])->name('reseller.payment.select-method');
        Route::post('reseller/invoices/{invoice}/pay', [App\Http\Controllers\Reseller\PaymentController::class, 'initiate'])->name('reseller.payment.initiate');
        Route::get('reseller/invoices/{invoice}/pay/mpesa/verify', [App\Http\Controllers\Reseller\PaymentController::class, 'verifyMpesa'])->name('reseller.payment.verify-mpesa');
        Route::get('reseller/invoices/{invoice}/pay/mpesa/status', [App\Http\Controllers\Reseller\PaymentController::class, 'mpesaStatus'])->name('reseller.payment.mpesa-status');
        Route::get('reseller/invoices/{invoice}/payment/success', [App\Http\Controllers\Reseller\PaymentController::class, 'success'])->name('reseller.payment.success');
        Route::get('reseller/invoices/{invoice}/payment/stripe/success', [App\Http\Controllers\Reseller\PaymentController::class, 'stripeSuccess'])->name('reseller.payment.stripe.success');
        Route::get('reseller/invoices/{invoice}/payment/stripe/cancel', [App\Http\Controllers\Reseller\PaymentController::class, 'stripeCancel'])->name('reseller.payment.stripe.cancel');
        Route::get('reseller/invoices/{invoice}/payment/paypal/success', [App\Http\Controllers\Reseller\PaymentController::class, 'paypalSuccess'])->name('reseller.payment.paypal.success');
        Route::get('reseller/invoices/{invoice}/payment/paypal/cancel', [App\Http\Controllers\Reseller\PaymentController::class, 'paypalCancel'])->name('reseller.payment.paypal.cancel');
        Route::get('reseller/invoices/{invoice}/payment/manual', [App\Http\Controllers\Reseller\PaymentController::class, 'manualForm'])->name('reseller.payment.manual-form');
        Route::post('reseller/invoices/{invoice}/payment/manual', [App\Http\Controllers\Reseller\PaymentController::class, 'manualSubmit'])->name('reseller.payment.manual-submit');
        Route::get('reseller/payments/{payment}/submitted', [App\Http\Controllers\Reseller\PaymentController::class, 'manualSubmitted'])->name('reseller.payment.manual-submitted');
    });

    // Customer-only routes
    Route::middleware(['customer', 'reseller.customer.catalog'])->group(function () {
        Route::get('/my/services', [App\Http\Controllers\Customer\ServiceController::class, 'index'])->name('customer.services.index');
        Route::get('/my/services/{service}', [App\Http\Controllers\Customer\ServiceController::class, 'show'])->name('customer.services.show');
        Route::post('/my/services/{service}/cancel', [App\Http\Controllers\Customer\ServiceController::class, 'cancel'])->name('customer.services.cancel');
        Route::get('/my/services/{service}/renew', [App\Http\Controllers\Customer\ServiceController::class, 'renewForm'])->name('customer.services.renew');
        Route::post('/my/services/{service}/renew', [App\Http\Controllers\Customer\ServiceController::class, 'renew'])->name('customer.services.renew.store');
        Route::get('/my/services/{service}/upgrade', [ServiceUpgradeController::class, 'show'])->name('customer.services.upgrade');
        Route::post('/my/services/{service}/upgrade', [ServiceUpgradeController::class, 'store'])->name('customer.services.upgrade.store');

        Route::prefix('my/services/{service}/hosting')->name('customer.services.hosting.')->group(function () {
            Route::get('panel-login', [HostingPanelController::class, 'panelLogin'])->middleware('throttle:10,1')->name('panel-login');
            Route::get('dashboard', [HostingPanelController::class, 'dashboard'])->middleware('throttle:60,1')->name('dashboard');
            Route::get('dns', [HostingPanelController::class, 'dnsIndex'])->name('dns.index');
            Route::post('dns', [HostingPanelController::class, 'dnsStore'])->middleware('throttle:30,1')->name('dns.store');
            Route::delete('dns', [HostingPanelController::class, 'dnsDestroy'])->middleware('throttle:30,1')->name('dns.destroy');
            Route::get('emails', [HostingPanelController::class, 'emailsIndex'])->name('emails.index');
            Route::post('emails', [HostingPanelController::class, 'emailsStore'])->middleware('throttle:20,1')->name('emails.store');
            Route::delete('emails', [HostingPanelController::class, 'emailsDestroy'])->middleware('throttle:20,1')->name('emails.destroy');
            Route::get('databases', [HostingPanelController::class, 'databasesIndex'])->name('databases.index');
            Route::post('databases', [HostingPanelController::class, 'databasesStore'])->middleware('throttle:20,1')->name('databases.store');
            Route::delete('databases', [HostingPanelController::class, 'databasesDestroy'])->middleware('throttle:20,1')->name('databases.destroy');
            Route::get('subdomains', [HostingPanelController::class, 'subdomainsIndex'])->name('subdomains.index');
            Route::post('subdomains', [HostingPanelController::class, 'subdomainsStore'])->middleware('throttle:20,1')->name('subdomains.store');
            Route::delete('subdomains', [HostingPanelController::class, 'subdomainsDestroy'])->middleware('throttle:20,1')->name('subdomains.destroy');
            Route::get('ftp', [HostingPanelController::class, 'ftpIndex'])->name('ftp.index');
            Route::post('ftp', [HostingPanelController::class, 'ftpStore'])->middleware('throttle:20,1')->name('ftp.store');
            Route::delete('ftp', [HostingPanelController::class, 'ftpDestroy'])->middleware('throttle:20,1')->name('ftp.destroy');
            Route::get('ssl', [HostingPanelController::class, 'sslShow'])->name('ssl.show');
            Route::post('ssl/letsencrypt', [HostingPanelController::class, 'sslLetsEncrypt'])->middleware('throttle:5,10')->name('ssl.letsencrypt');
            Route::get('cron', [HostingPanelController::class, 'cronIndex'])->name('cron.index');
            Route::post('cron', [HostingPanelController::class, 'cronStore'])->middleware('throttle:20,1')->name('cron.store');
            Route::delete('cron', [HostingPanelController::class, 'cronDestroy'])->middleware('throttle:20,1')->name('cron.destroy');
            Route::get('backups', [HostingPanelController::class, 'backupsIndex'])->name('backups.index');
            Route::post('backups', [HostingPanelController::class, 'backupsStore'])->middleware('throttle:5,10')->name('backups.store');
            Route::post('password/reset', [HostingPanelController::class, 'resetPassword'])->middleware('throttle:5,10')->name('password.reset');
        });

        Route::get('/my/servers', [App\Http\Controllers\Customer\ServerController::class, 'index'])->name('customer.servers.index');
        Route::post('/my/servers/order', [App\Http\Controllers\Customer\ServerController::class, 'order'])->name('customer.servers.order');
        Route::resource('my/orders', App\Http\Controllers\Customer\OrderController::class)->only(['index', 'show'])->names('customer.orders');
        Route::post('my/orders/{order}/cancel', [App\Http\Controllers\Customer\OrderController::class, 'cancel'])->name('customer.orders.cancel');
        Route::get('my/credits', [App\Http\Controllers\Customer\CreditController::class, 'index'])->name('customer.credits.index');
        Route::post('my/credits/topup', [App\Http\Controllers\Customer\CreditController::class, 'initiateTopup'])->name('customer.credits.topup');
        Route::get('my/credits/topup/status/{invoice}', [App\Http\Controllers\Customer\CreditController::class, 'checkTopupStatus'])->name('customer.credits.topup.status');
        Route::resource('my/invoices', App\Http\Controllers\Customer\InvoiceController::class)->only(['index', 'show'])->names('customer.invoices');
        Route::get('my/invoices/{invoice}/download', [App\Http\Controllers\Customer\InvoiceController::class, 'download'])->name('customer.invoices.download');
        Route::get('my/invoices/{invoice}/preview', [App\Http\Controllers\Customer\InvoiceController::class, 'preview'])->name('customer.invoices.preview');
        Route::resource('my/payments', PaymentController::class)->only(['index', 'show'])->names('customer.payments');

        // Shopping experience
        Route::get('/select-techstack', [ServiceBrowserController::class, 'selectTechstack'])->name('customer.select-techstack');
        Route::get('/confirm-techstack', [ServiceBrowserController::class, 'showConfirmTechstack'])->name('customer.confirm-techstack');
        Route::post('/confirm-techstack', [ServiceBrowserController::class, 'confirmTechstack'])->name('customer.confirm-techstack.store');
        Route::get('/api/languages/{language}/databases', [ServiceBrowserController::class, 'getAvailableDatabases'])->name('api.languages.databases');
        Route::get('/api/databases/{database}/languages', [ServiceBrowserController::class, 'getAvailableLanguages'])->name('api.databases.languages');
        Route::get('/api/products', [ServiceBrowserController::class, 'getAvailableProducts'])->name('api.products');
        Route::get('/deploy-service', [ServiceBrowserController::class, 'index'])->name('customer.deploy-service');
        Route::get('/browse-services', [ServiceBrowserController::class, 'browse'])->name('customer.browse-services');
        Route::redirect('/my/reseller-catalog', '/my/catalog');
        Route::get('/my/catalog', [ResellerCatalogController::class, 'index'])->name('customer.catalog.index');
        Route::post('/my/catalog/{resellerProduct}/add', [ResellerCatalogController::class, 'addToCart'])->name('customer.catalog.add');
        Route::get('/my/domains', [App\Http\Controllers\Customer\DomainController::class, 'index'])->name('customer.domains.index');
        Route::get('/my/domains/transfer/approval/{token}', [InterCustomerDomainTransferController::class, 'show'])->name('customer.domains.inter-transfer.approval');
        Route::post('/my/domains/transfer/approval/{token}/approve', [InterCustomerDomainTransferController::class, 'approve'])->name('customer.domains.inter-transfer.approve');
        Route::post('/my/domains/transfer/approval/{token}/reject', [InterCustomerDomainTransferController::class, 'reject'])->name('customer.domains.inter-transfer.reject');
        Route::get('/my/domains/transfer', [App\Http\Controllers\Customer\DomainController::class, 'showTransferForm'])->name('customer.domains.transfer-form');
        Route::post('/my/domains/transfer', [App\Http\Controllers\Customer\DomainController::class, 'processTransfer'])->name('customer.domains.process-transfer');
        Route::get('/my/domains/transfer/checkout', [App\Http\Controllers\Customer\DomainController::class, 'showTransferCheckout'])->name('customer.domains.transfer-checkout');
        Route::post('/my/domains/transfer/checkout/confirm', [App\Http\Controllers\Customer\DomainController::class, 'confirmTransferCheckout'])->name('customer.domains.transfer-checkout-confirm');
        Route::get('/my/domains/{domain}/transfer', [App\Http\Controllers\Customer\DomainController::class, 'showTransferDetails'])->name('customer.domains.transfer-details');
        Route::post('/my/domains/{domain}/transfer/cancel', [App\Http\Controllers\Customer\DomainController::class, 'cancelTransfer'])->name('customer.domains.cancel-transfer');

        // Domain renewal
        Route::post('/my/domains/{domain}/renew', [App\Http\Controllers\Customer\DomainController::class, 'initiateRenewal'])->name('customer.domains.initiate-renewal');
        Route::get('/my/domains/renewal/checkout', [App\Http\Controllers\Customer\DomainController::class, 'showRenewalCheckout'])->name('customer.domains.renewal-checkout');
        Route::post('/my/domains/renewal/checkout/confirm', [App\Http\Controllers\Customer\DomainController::class, 'confirmRenewalCheckout'])->name('customer.domains.renewal-checkout-confirm');

        // DNS management
        Route::get('/my/domains/{domain}/dns', [DnsController::class, 'index'])->name('customer.domains.dns.index');
        Route::post('/my/domains/{domain}/dns/provision', [DnsController::class, 'provision'])->name('customer.domains.dns.provision');
        Route::get('/my/domains/{domain}/dns/nameservers', [DnsController::class, 'nameservers'])->name('customer.domains.dns.nameservers');
        Route::post('/my/domains/{domain}/dns/nameservers', [DnsController::class, 'updateNameservers'])->name('customer.domains.dns.update-nameservers');
        Route::post('/my/domains/{domain}/dns/records', [DnsController::class, 'addRecord'])->name('customer.domains.dns.add-record');
        Route::patch('/my/domains/{domain}/dns/records/{recordId}', [DnsController::class, 'updateRecord'])->name('customer.domains.dns.update-record');
        Route::delete('/my/domains/{domain}/dns/records/{recordId}', [DnsController::class, 'deleteRecord'])->name('customer.domains.dns.delete-record');

        Route::get('/domains/search', [DomainSearchController::class, 'search'])->name('domains.search');

        // Shopping cart
        Route::get('/cart', [App\Http\Controllers\Customer\CartController::class, 'index'])->name('customer.cart.index');
        Route::post('/cart/add', [App\Http\Controllers\Customer\CartController::class, 'add'])->name('customer.cart.add');
        Route::delete('/cart/{key}', [App\Http\Controllers\Customer\CartController::class, 'remove'])->name('customer.cart.remove');
        Route::post('/cart/clear', [App\Http\Controllers\Customer\CartController::class, 'clear'])->name('customer.cart.clear');
        Route::post('/cart/check-domain', [App\Http\Controllers\Customer\CartController::class, 'checkDomainAvailability'])->name('customer.cart.check-domain');
        Route::post('/cart/{key}/nameservers', [App\Http\Controllers\Customer\CartController::class, 'updateNameservers'])->name('customer.cart.nameservers');
        Route::post('/cart/{key}/cloudflare-dns', [App\Http\Controllers\Customer\CartController::class, 'updateCloudflareDns'])->name('customer.cart.cloudflare-dns');
        Route::get('/cart/attach-hosting', [App\Http\Controllers\Customer\CartController::class, 'attachHosting'])->name('customer.cart.attach-hosting');

        // Checkout
        Route::get('/checkout', [CheckoutController::class, 'show'])->name('customer.checkout.show');
        Route::post('/checkout', [CheckoutController::class, 'process'])->name('customer.checkout.process');

        // Payment methods (resource routes already defined above, these are additional payment workflows)
        Route::get('/invoices/{invoice}/pay', [PaymentController::class, 'selectMethod'])->name('customer.payment.select-method');
        Route::post('/invoices/{invoice}/apply-credits', [PaymentController::class, 'applyCredits'])->name('customer.payment.apply-credits');
        Route::post('/invoices/{invoice}/pay', [PaymentController::class, 'initiate'])->name('customer.payment.initiate');
        Route::get('/invoices/{invoice}/pay/mpesa/verify', [PaymentController::class, 'verifyMpesa'])->name('customer.payment.verify-mpesa');
        Route::get('/invoices/{invoice}/pay/mpesa/status', [PaymentController::class, 'mpesaStatus'])->name('customer.payment.mpesa-status');
        Route::get('/invoices/{invoice}/payment/success', [PaymentController::class, 'success'])->name('customer.payment.success');
        Route::get('/invoices/{invoice}/payment/stripe/success', [PaymentController::class, 'stripeSuccess'])->name('customer.payment.stripe.success');
        Route::get('/invoices/{invoice}/payment/stripe/cancel', [PaymentController::class, 'stripeCancel'])->name('customer.payment.stripe.cancel');
        Route::get('/invoices/{invoice}/payment/paypal/success', [PaymentController::class, 'paypalSuccess'])->name('customer.payment.paypal.success');
        Route::get('/invoices/{invoice}/payment/paypal/cancel', [PaymentController::class, 'paypalCancel'])->name('customer.payment.paypal.cancel');
        Route::get('/invoices/{invoice}/payment/manual', [PaymentController::class, 'manualPaymentForm'])->name('customer.payment.manual-form');
        Route::post('/invoices/{invoice}/payment/manual', [PaymentController::class, 'submitManualPayment'])->name('customer.payment.manual-submit');
        Route::get('/payments/{payment}/submitted', [PaymentController::class, 'manualPaymentSubmitted'])->name('customer.payment.manual-submitted');
        Route::get('/invoices/{invoice}/payment/bank-transfer', [PaymentController::class, 'bankTransferForm'])->name('customer.payment.bank-transfer-form');
        Route::post('/invoices/{invoice}/payment/bank-transfer', [PaymentController::class, 'submitBankTransfer'])->name('customer.payment.bank-transfer-submit');
        Route::get('/payments/{payment}/bank-transfer-submitted', [PaymentController::class, 'bankTransferSubmitted'])->name('customer.payment.bank-transfer-submitted');

        // Container management
        Route::get('my/services/{service}/container', [App\Http\Controllers\Customer\ContainerController::class, 'show'])->name('customer.services.container.show');
        Route::delete('my/services/{service}/container', [App\Http\Controllers\Customer\ContainerController::class, 'destroy'])->name('customer.services.container.destroy');
        Route::post('my/services/{service}/container/restart', [App\Http\Controllers\Customer\ContainerController::class, 'restart'])->name('customer.services.container.restart');
        Route::post('my/services/{service}/container/redeploy', [App\Http\Controllers\Customer\ContainerController::class, 'redeploy'])->middleware('throttle:10,10')->name('customer.services.container.redeploy');
        Route::post('my/services/{service}/container/initialize-laravel', [App\Http\Controllers\Customer\ContainerController::class, 'initializeLaravel'])->middleware('throttle:laravel-container-actions')->name('customer.services.container.initialize-laravel');
        Route::post('my/services/{service}/container/clear-app', [App\Http\Controllers\Customer\ContainerController::class, 'clearAppDirectory'])->middleware('throttle:laravel-container-actions')->name('customer.services.container.clear-app');
        Route::get('my/services/{service}/container/laravel-setup', [App\Http\Controllers\Customer\ContainerController::class, 'laravelSetupStatus'])->name('customer.services.container.laravel-setup');
        Route::post('my/services/{service}/container/php-extensions', [App\Http\Controllers\Customer\ContainerController::class, 'updatePhpExtensions'])->middleware('throttle:10,10')->name('customer.services.container.php-extensions.update');
        Route::post('my/services/{service}/container/git-repository', [App\Http\Controllers\Customer\ContainerController::class, 'updateGitRepository'])->middleware('throttle:laravel-container-actions')->name('customer.services.container.git-repository.update');
        Route::post('my/services/{service}/container/git-repository/pull', [App\Http\Controllers\Customer\ContainerController::class, 'pullGitRepository'])->middleware('throttle:laravel-container-actions')->name('customer.services.container.git-repository.pull');
        Route::get('my/services/{service}/container/git-repository/status', [App\Http\Controllers\Customer\ContainerController::class, 'gitPullStatus'])->name('customer.services.container.git-repository.status');
        Route::post('my/services/{service}/container/stop', [App\Http\Controllers\Customer\ContainerController::class, 'stop'])->name('customer.services.container.stop');
        Route::post('my/services/{service}/container/start', [App\Http\Controllers\Customer\ContainerController::class, 'start'])->name('customer.services.container.start');
        Route::get('my/services/{service}/container/logs', [App\Http\Controllers\Customer\ContainerController::class, 'logs'])->name('customer.services.container.logs');
        Route::get('my/services/{service}/container/metrics', [App\Http\Controllers\Customer\ContainerController::class, 'metrics'])->name('customer.services.container.metrics');
        Route::get('my/services/{service}/container/health', [App\Http\Controllers\Customer\ContainerController::class, 'health'])->name('customer.services.container.health');
        Route::get('my/services/{service}/container/storage-stats', [App\Http\Controllers\Customer\ContainerController::class, 'storageStats'])->name('customer.services.container.storage-stats');
        Route::post('my/services/{service}/container/database/query', [App\Http\Controllers\Customer\ContainerController::class, 'databaseQuery'])->middleware('throttle:10,1')->name('customer.services.container.database.query');
        Route::post('my/services/{service}/container/database/import', [App\Http\Controllers\Customer\ContainerController::class, 'databaseImport'])->middleware('throttle:3,10')->name('customer.services.container.database.import');
        Route::get('my/services/{service}/container/database/history', [App\Http\Controllers\Customer\ContainerController::class, 'databaseHistory'])->middleware('throttle:20,1')->name('customer.services.container.database.history');
        Route::post('my/services/{service}/container/database/test-connection', [App\Http\Controllers\Customer\ContainerController::class, 'databaseTestConnection'])->middleware('throttle:5,1')->name('customer.services.container.database.test');
        Route::post('my/services/{service}/container/database/sync-credentials', [App\Http\Controllers\Customer\ContainerController::class, 'databaseSyncCredentials'])->middleware('throttle:3,1')->name('customer.services.container.database.sync');

        // Container file manager (throttled)
        Route::middleware(['throttle:60,1'])->group(function () {
            Route::get('my/services/{service}/container/files', [ContainerFileController::class, 'index'])->name('customer.services.container.files.index');
            Route::get('my/services/{service}/container/files/content', [ContainerFileController::class, 'content'])->name('customer.services.container.files.content');
            Route::put('my/services/{service}/container/files/content', [ContainerFileController::class, 'saveContent'])->middleware('throttle:30,1')->name('customer.services.container.files.save');
            Route::get('my/services/{service}/container/files/download', [ContainerFileController::class, 'download'])->name('customer.services.container.files.download');
            Route::post('my/services/{service}/container/files/upload', [ContainerFileController::class, 'upload'])->middleware('throttle:10,1')->name('customer.services.container.files.upload');
            Route::delete('my/services/{service}/container/files', [ContainerFileController::class, 'delete'])->name('customer.services.container.files.delete');
            Route::post('my/services/{service}/container/files/mkdir', [ContainerFileController::class, 'mkdir'])->name('customer.services.container.files.mkdir');
            Route::post('my/services/{service}/container/files/create', [ContainerFileController::class, 'createFile'])->name('customer.services.container.files.create');
            Route::patch('my/services/{service}/container/files/rename', [ContainerFileController::class, 'rename'])->name('customer.services.container.files.rename');
        });

        // Container terminal (throttled separately)
        Route::post('my/services/{service}/terminal', [ContainerTerminalController::class, 'create'])->middleware('throttle:5,1')->name('customer.services.container.terminal.create');
        Route::post('my/services/{service}/terminal/execute', [ContainerTerminalController::class, 'execute'])->middleware('throttle:60,1')->name('customer.services.container.terminal.execute');
        Route::delete('my/services/{service}/terminal', [ContainerTerminalController::class, 'close'])->name('customer.services.container.terminal.close');

        Route::post('my/services/{service}/container/domains', [App\Http\Controllers\Customer\ContainerController::class, 'bindDomain'])->name('customer.services.container.domains.bind');
        Route::patch('my/services/{service}/container/domains/{domain}', [App\Http\Controllers\Customer\ContainerController::class, 'updateDomain'])->name('customer.services.container.domains.update');
        Route::delete('my/services/{service}/container/domains/{domain}', [App\Http\Controllers\Customer\ContainerController::class, 'unbindDomain'])->name('customer.services.container.domains.unbind');
        Route::post('my/services/{service}/container/domains/{domain}/ssl', [App\Http\Controllers\Customer\ContainerController::class, 'enableSsl'])->name('customer.services.container.domains.ssl');
        Route::post('my/services/{service}/container/backups', [App\Http\Controllers\Customer\ContainerController::class, 'createBackup'])->name('customer.services.container.backups.create');
        Route::post('my/services/{service}/container/backups/{backup}/restore', [App\Http\Controllers\Customer\ContainerController::class, 'restoreBackup'])->name('customer.services.container.backups.restore');
        Route::delete('my/services/{service}/container/backups/{backup}', [App\Http\Controllers\Customer\ContainerController::class, 'deleteBackup'])->name('customer.services.container.backups.delete');

        Route::get('/my/domains/available', fn () => view('customer.domains.available', ['extensions' => DomainExtension::where('enabled', true)->get()]))->name('customer.domains.available');

        // Customer Ticket Management
        Route::resource('my/tickets', App\Http\Controllers\Customer\TicketController::class)
            ->only(['index', 'show', 'create', 'store'])->names('customer.tickets');
        Route::post('my/tickets/{ticket}/reply', [App\Http\Controllers\Customer\TicketController::class, 'reply'])->name('customer.tickets.reply');
        Route::patch('my/tickets/{ticket}/close', [App\Http\Controllers\Customer\TicketController::class, 'close'])->name('customer.tickets.close');
        Route::get('my/tickets/{ticket}/attachments/{attachment}', [TicketAttachmentController::class, 'show'])->name('customer.tickets.attachments.show');
    });

    // Profile (accessible to all authenticated users)
    Route::get('/profile/notifications', [NotificationPreferenceController::class, 'edit'])->name('profile.notifications');
    Route::patch('/profile/notifications', [NotificationPreferenceController::class, 'update'])->name('profile.notifications.update');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/security', [ProfileController::class, 'security'])->name('profile.security');
    Route::post('/security/logout-other-sessions', [ProfileController::class, 'logoutOtherSessions'])->name('profile.logout-other-sessions');
    Route::post('/security/two-factor/enable', [ProfileController::class, 'enableTwoFactor'])->name('profile.two-factor.enable');
    Route::post('/security/two-factor/disable', [ProfileController::class, 'disableTwoFactor'])->name('profile.two-factor.disable');
    Route::post('/security/two-factor/regenerate-codes', [ProfileController::class, 'regenerateRecoveryCodes'])->name('profile.two-factor.regenerate-codes');
});

require __DIR__.'/auth.php';
