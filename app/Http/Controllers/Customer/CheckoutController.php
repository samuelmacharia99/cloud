<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Rules\ValidCountryCode;
use App\Services\Billing\InvoiceNumberService;
use App\Services\Billing\InvoiceSettlementService;
use App\Services\Checkout\ContainerEmailBundleService;
use App\Services\Checkout\EmailHostingCheckoutService;
use App\Services\Checkout\ProjectHostingCheckoutService;
use App\Services\Checkout\SharedHostingCheckoutService;
use App\Services\CreditService;
use App\Services\Customer\CustomerProjectService;
use App\Services\Dns\DomainCloudflareDnsService;
use App\Services\DomainTransferService;
use App\Services\EmailVerificationService;
use App\Services\NotificationService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use App\Services\RegistrationGuardService;
use App\Services\ResellerBrandingResolver;
use App\Services\ResellerCheckoutGuardService;
use App\Services\ResellerCustomerCatalogService;
use App\Services\ResellerDomainOrderService;
use App\Services\ResellerHostingSetupService;
use App\Services\ResellerLandingService;
use App\Services\ResellerNameserverService;
use App\Services\ResellerPackageSubscriptionService;
use App\Services\ResellerPublicApiService;
use App\Services\ResellerStorefrontPromoService;
use App\Services\ServerProductConfigService;
use App\Services\TaxService;
use App\Services\TechStackRoutingService;
use App\Services\UserCurrencyService;
use App\Support\SessionCart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class CheckoutController extends Controller
{
    /** @deprecated Use SessionCart — kept for tests referencing the legacy key name */
    const CART_SESSION_KEY = SessionCart::LEGACY_PORTAL_KEY;

    /**
     * Show checkout page
     */
    public function show(Request $request)
    {
        // Check if an invoice_id is provided (for direct invoice checkout like domain transfers)
        if ($request->has('invoice_id')) {
            $invoice = Invoice::where('id', $request->invoice_id)
                ->where('user_id', auth()->id())
                ->firstOrFail();

            $currency = app(UserCurrencyService::class)->model(auth()->user());
            $currencyCode = $currency->code;

            return view('customer.checkout.invoice', [
                'invoice' => $invoice,
                'user' => auth()->user(),
                'currency' => $currency,
                'currencyCode' => $currencyCode,
                'availableGateways' => PaymentGatewayFactory::getAvailableGatewaysForInvoice($invoice),
            ]);
        }

        // Get cart from session and localStorage (domains)
        $cart = SessionCart::active();

        if (empty($cart)) {
            return redirect()->route('customer.cart.index')->with('error', 'Your cart is empty');
        }

        $user = auth()->user();

        if (app(ResellerCustomerCatalogService::class)->isResellerCustomer($user)) {
            try {
                app(ResellerCheckoutGuardService::class)->assertCheckoutAllowed($user);
            } catch (\InvalidArgumentException $e) {
                return redirect()->route('customer.cart.index')->with('error', $e->getMessage());
            }
        }

        // Prepare cart items with details
        $cartItems = [];
        $subtotal = 0;

        foreach ($cart as $key => $item) {
            $item['key'] = $key;

            if ($item['type'] === 'product') {
                if (app(ResellerCustomerCatalogService::class)->isResellerCustomer($user)) {
                    continue;
                }

                $product = Product::find($item['product_id']);
                if (! $product) {
                    continue;
                }

                $item['name'] = $product->name;
                $item['description'] = $product->description ?? $product->name;
                $item['type'] = $product->type;
                $pricing = $this->resolveCartItemPricing($product, $item);
                $item['unit_price'] = $pricing['unit_price'];
                $item['amount'] = $pricing['unit_price'] + $pricing['setup_fee'];

                // Load container template if applicable
                if ($product->type === 'container_hosting' && $product->containerTemplate) {
                    $item['container_template'] = $product->containerTemplate;
                }
            } elseif ($item['type'] === 'reseller_product') {
                $prepared = $this->prepareResellerProductCartItem($item);
                if ($prepared === null) {
                    continue;
                }
                $item = $prepared;
            } elseif ($item['type'] === 'reseller_package') {
                $prepared = $this->prepareResellerPackageCartItem($item);
                if ($prepared === null) {
                    continue;
                }
                $item = $prepared;
            } elseif ($item['type'] === 'domain') {
                $extension = DomainExtension::where('extension', $item['extension'])->first();
                if (! $extension) {
                    continue;
                }

                $price = $this->domainRegistrationPrice($user, $extension, (int) $item['years']);
                if ($price === null) {
                    continue;
                }

                $item['unit_price'] = $price;
                $item['amount'] = $item['unit_price'];
                $item['name'] = "{$item['domain']}{$item['extension']}";
                $item['description'] = "Domain registration for {$item['years']} year(s)";
            } elseif ($item['type'] === 'domain_transfer') {
                $prepared = $this->prepareDomainTransferCartItem($user, $item);
                if ($prepared === null) {
                    continue;
                }
                $item = $prepared;
            }

            $subtotal += $item['amount'];
            $cartItems[] = $item;
        }

        if (empty($cartItems)) {
            return redirect()->route('customer.cart.index')->with('error', 'No valid items in cart');
        }

        $bundleCheckout = app(ContainerEmailBundleService::class);
        $subtotal += $bundleCheckout->estimateInvoiceAddonTotal($cart);

        $taxBreakdown = TaxService::calculateForUser($subtotal, $user);

        $currency = app(UserCurrencyService::class)->model($user);
        $currencyCode = $currency->code;

        $sharedHostingItems = array_values(array_filter(
            $cartItems,
            fn ($item) => ($item['type'] ?? null) === 'shared_hosting'
        ));

        $emailHostingItems = [];
        foreach ($cartItems as $item) {
            if (empty($item['product_id'])) {
                continue;
            }

            // show() rewrites item type to the product type (e.g. email_hosting).
            if (($item['type'] ?? null) === 'email_hosting') {
                $emailHostingItems[] = $item;

                continue;
            }

            if (($item['type'] ?? null) === 'product') {
                $product = Product::find($item['product_id']);
                if ($product && $product->type === 'email_hosting') {
                    $emailHostingItems[] = $item;
                }
            }
        }

        $domainExtensions = DomainExtension::where('enabled', true)->orderBy('extension')->get();
        $nameserverService = app(ResellerNameserverService::class);
        $defaultNameservers = $nameserverService->defaultsForCustomer($user);

        $hostingCheckout = app(SharedHostingCheckoutService::class);
        $emailCheckout = app(EmailHostingCheckoutService::class);
        $bundledContainerItems = $bundleCheckout->bundledContainerItems($cart);
        $linkedHostingDomains = [];
        foreach ($sharedHostingItems as $item) {
            if ($details = $hostingCheckout->linkedDomainDetails($cart, $item['key'])) {
                $linkedHostingDomains[$item['key']] = $details;
            }
        }

        $linkedEmailDomains = [];
        foreach ($emailHostingItems as $item) {
            if ($details = $hostingCheckout->linkedDomainDetails($cart, $item['key'])) {
                $linkedEmailDomains[$item['key']] = $details;
            }
        }

        $customerDomains = Domain::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get();

        return view('customer.checkout.index', [
            'cartItems' => $cartItems,
            'sharedHostingItems' => $sharedHostingItems,
            'emailHostingItems' => $emailHostingItems,
            'bundledContainerItems' => $bundledContainerItems,
            'customerDomains' => $customerDomains,
            'linkedHostingDomains' => $linkedHostingDomains,
            'linkedEmailDomains' => $linkedEmailDomains,
            'domainExtensions' => $domainExtensions,
            'defaultNameservers' => $defaultNameservers,
            'subtotal' => $taxBreakdown['subtotal'],
            'tax' => $taxBreakdown['tax'],
            'taxEnabled' => $taxBreakdown['enabled'],
            'taxRate' => $taxBreakdown['rate'],
            'taxInclusive' => $taxBreakdown['inclusive'],
            'taxName' => $taxBreakdown['name'],
            'total' => $taxBreakdown['total'],
            'cartSubtotal' => $subtotal,
            'user' => auth()->user(),
            'currency' => $currency,
            'currencyCode' => $currencyCode,
            'creditBalance' => CreditService::getAvailableBalance($user),
        ]);
    }

    /**
     * Process checkout and create order
     */
    public function process(Request $request)
    {
        $request->validate([
            'agree_terms' => 'required|accepted',
            'apply_credits' => 'sometimes|boolean',
            'source_repo_url.*' => 'nullable|url|max:500',
            'source_repo_branch.*' => 'nullable|string|max:120|regex:/^[A-Za-z0-9._\\/-]+$/',
        ]);

        $cart = SessionCart::active();

        if (empty($cart)) {
            return back()->with('error', 'Your cart is empty');
        }

        $user = auth()->user();

        if (app(ResellerCustomerCatalogService::class)->isResellerCustomer($user)) {
            try {
                app(ResellerCheckoutGuardService::class)->assertCheckoutAllowed($user);
            } catch (\InvalidArgumentException $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        app(SharedHostingCheckoutService::class)->validateCheckoutRequest($request, $cart);
        app(EmailHostingCheckoutService::class)->validateCheckoutRequest($request, $cart);
        app(ContainerEmailBundleService::class)->validateCheckoutRequest($request, $cart);

        try {
            $order = \DB::transaction(function () use ($cart, $user, $request) {
                // Get cart items with details
                $cartItems = [];
                $subtotal = 0;

                foreach ($cart as $key => $item) {
                    $item['key'] = $key;

                    if ($item['type'] === 'product') {
                        if (app(ResellerCustomerCatalogService::class)->isResellerCustomer($user)) {
                            continue;
                        }

                        $product = Product::find($item['product_id']);
                        if (! $product) {
                            continue;
                        }

                        $pricing = $this->resolveCartItemPricing($product, $item);
                        $item['unit_price'] = $pricing['unit_price'];
                        $item['amount'] = $pricing['unit_price'] + $pricing['setup_fee'];
                    } elseif ($item['type'] === 'reseller_product') {
                        $prepared = $this->prepareResellerProductCartItem($item);
                        if ($prepared === null) {
                            continue;
                        }
                        $item = $prepared;
                    } elseif ($item['type'] === 'domain') {
                        $extension = DomainExtension::where('extension', $item['extension'])->first();
                        if (! $extension) {
                            continue;
                        }

                        $price = $this->domainRegistrationPrice($user, $extension, (int) $item['years']);
                        if ($price === null) {
                            continue;
                        }

                        $item['unit_price'] = $price;
                        $item['amount'] = $item['unit_price'];
                    } elseif ($item['type'] === 'domain_transfer') {
                        $prepared = $this->prepareDomainTransferCartItem($user, $item);
                        if ($prepared === null) {
                            continue;
                        }
                        $item = $prepared;
                    }

                    $subtotal += $item['amount'];
                    $cartItems[] = $item;
                }

                if (empty($cartItems)) {
                    throw new \Exception('No valid items in cart');
                }

                $hostingCheckout = app(SharedHostingCheckoutService::class);
                $emailCheckout = app(EmailHostingCheckoutService::class);
                $cartItems = $hostingCheckout->sortCartItemsDomainsFirst($cartItems);
                $domainsCreatedByCartKey = [];

                $domainAddonTotal = $hostingCheckout->estimateDomainAddonTotal($request, $cart)
                    + $emailCheckout->estimateDomainAddonTotal($request, $cart)
                    + app(ContainerEmailBundleService::class)->estimateInvoiceAddonTotal($cart);

                $subtotal += $domainAddonTotal;
                $promo = $this->storefrontPromoForCheckout($subtotal);
                $taxBreakdown = TaxService::calculateForUser($promo['taxable'], $user);

                // Create Invoice first (so we have the ID for the order)
                $invoice = Invoice::create([
                    'user_id' => $user->id,
                    'invoice_number' => $this->generateInvoiceNumber(),
                    'status' => 'unpaid',
                    'due_date' => now()->addDays((int) Setting::getValue('invoice_due_days', 30)),
                    'subtotal' => $taxBreakdown['subtotal'],
                    'tax' => $taxBreakdown['tax'],
                    'total' => $taxBreakdown['total'],
                ]);

                // Create Order linked to Invoice
                $order = Order::create([
                    'user_id' => $user->id,
                    'invoice_id' => $invoice->id,
                    'order_number' => 'ORD-'.uniqid(),
                    'status' => 'pending',
                    'payment_status' => 'unpaid',
                    'subtotal' => $taxBreakdown['subtotal'],
                    'tax' => $taxBreakdown['tax'],
                    'total' => $taxBreakdown['total'],
                ]);

                // Create OrderItems, Services, and Domains
                foreach ($cartItems as $item) {
                    if ($this->isProductCheckoutItem($item['type'] ?? null)) {
                        $product = Product::find($item['product_id']);

                        // Create OrderItem
                        $orderItem = OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'description' => $item['name'] ?? $product->name,
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                            'billing_cycle' => $item['billing_cycle'],
                            'custom_options' => [],
                        ]);

                        // Prepare service metadata
                        $serviceMeta = [];
                        $nodeId = null;

                        // For container products, collect environment variables, version, and database selection
                        if ($product->type === 'container_hosting') {
                            $envValuesKey = "env_values[{$item['key']}]";
                            $envValues = $request->input($envValuesKey, []);
                            if (! empty($envValues)) {
                                $serviceMeta['env_values'] = $envValues;
                            }

                            // Store selected version for templated containers
                            $selectedVersionKey = "selected_version[{$item['key']}]";
                            $selectedVersion = $request->input($selectedVersionKey);
                            if (! empty($selectedVersion)) {
                                $serviceMeta['selected_version'] = $selectedVersion;
                            }

                            // Store selected stack builder roles + database for provisioning
                            $serviceMeta = TechStackRoutingService::applySessionSelectionToServiceMeta($serviceMeta);

                            $serviceMeta = $this->applyResellerContainerServiceMeta($serviceMeta, $product, $user, $item);

                            // Optional app source to deploy into container filesystem.
                            $sourceRepoUrl = $request->input("source_repo_url.{$item['key']}");
                            if (! empty($sourceRepoUrl)) {
                                $serviceMeta['source_repo_url'] = $sourceRepoUrl;
                                $serviceMeta['source_repo_branch'] = $request->input("source_repo_branch.{$item['key']}", 'main');
                            }
                        }

                        // For DirectAdmin shared hosting, collect domain + credentials from checkout form
                        if ($product->type === 'shared_hosting' && $product->provisioning_driver_key === 'directadmin') {
                            $resellerProduct = ! empty($item['reseller_product_id'])
                                ? ResellerProduct::find($item['reseller_product_id'])
                                : null;

                            $hostingContext = app(SharedHostingCheckoutService::class)->buildSharedHostingContext(
                                $request,
                                $item['key'],
                                $user,
                                $product,
                                $invoice,
                                $order,
                                $resellerProduct,
                                $cart,
                                $domainsCreatedByCartKey,
                            );
                            $serviceMeta = array_merge($serviceMeta, $hostingContext['service_meta']);
                            $nodeId = $hostingContext['node_id'];
                            $serviceMeta = TechStackRoutingService::applySessionSelectionToServiceMeta($serviceMeta);
                            app(SharedHostingCheckoutService::class)->persistExtraInvoiceItems(
                                $invoice,
                                $order,
                                $hostingContext['invoice_items']
                            );
                        }

                        if ($product->type === 'email_hosting') {
                            $emailContext = app(EmailHostingCheckoutService::class)->buildEmailHostingContext(
                                $request,
                                $item['key'],
                                $user,
                                $product,
                                $invoice,
                                $order,
                                $cart,
                                $domainsCreatedByCartKey,
                            );
                            $serviceMeta = array_merge($serviceMeta, $emailContext['service_meta']);
                            $nodeId = $emailContext['node_id'];
                        }

                        // For server types, capture OS and IP count from cart item
                        if (Product::isServerType($product->type)) {
                            if (! empty($item['operating_system'])) {
                                $serviceMeta['operating_system'] = $item['operating_system'];
                            }
                            if (! empty($item['ip_count'])) {
                                $serviceMeta['ip_count'] = (int) $item['ip_count'];
                            }
                            if (! empty($item['location_key'])) {
                                $serviceMeta['location_key'] = $item['location_key'];
                            }
                            if (! empty($item['location_name'])) {
                                $serviceMeta['location_name'] = $item['location_name'];
                            }
                            if (! empty($item['location_city'])) {
                                $serviceMeta['location_city'] = $item['location_city'];
                            }
                        }

                        // Determine provisioning driver
                        $provisioningDriver = $product->provisioning_driver_key;
                        if (! $provisioningDriver && Product::isServerType($product->type)) {
                            $provisioningDriver = 'server';
                        }

                        if ($product->type === 'container_hosting') {
                            $domainHint = $serviceMeta['primary_domain']
                                ?? $request->input("primary_domain.{$item['key']}")
                                ?? $request->input("domain.{$item['key']}")
                                ?? null;

                            $projectBundle = app(ProjectHostingCheckoutService::class)->createFromCartItem(
                                $user,
                                $product,
                                $order,
                                $orderItem,
                                $invoice,
                                $item,
                                $serviceMeta,
                                is_string($domainHint) ? $domainHint : null,
                            );

                            if ($projectBundle !== null) {
                                if ($request) {
                                    app(ContainerEmailBundleService::class)->attachToContainerService(
                                        $request,
                                        $item['key'],
                                        $user,
                                        $product,
                                        $projectBundle['billing_service'],
                                        $invoice,
                                        $order,
                                        $item,
                                    );
                                }

                                continue;
                            }
                        }

                        // Create Service
                        $service = Service::create([
                            'user_id' => $user->id,
                            'product_id' => $product->id,
                            'order_item_id' => $orderItem->id,
                            'invoice_id' => $invoice->id,
                            'reseller_id' => $item['reseller_id'] ?? $user->reseller_id,
                            'name' => $item['name'] ?? $product->name,
                            'status' => 'pending',
                            'billing_cycle' => $item['billing_cycle'],
                            'custom_price' => $item['unit_price'],
                            'next_due_date' => now()->addMonths($this->billingCycleMonths($item['billing_cycle'])),
                            'provisioning_driver_key' => $provisioningDriver,
                            'node_id' => $nodeId,
                            'service_meta' => $serviceMeta,
                        ]);

                        if ($product->type === 'container_hosting') {
                            app(CustomerProjectService::class)->syncRelated($service->fresh());
                        }

                        if ($product->type === 'container_hosting' && $request) {
                            app(ContainerEmailBundleService::class)->attachToContainerService(
                                $request,
                                $item['key'],
                                $user,
                                $product,
                                $service,
                                $invoice,
                                $order,
                                $item,
                            );
                        }

                        // Create InvoiceItem
                        InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'service_id' => $service->id,
                            'product_id' => $product->id,
                            'description' => $item['name'] ?? $product->name,
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                        ]);
                    } elseif ($item['type'] === 'domain') {
                        $extension = DomainExtension::where('extension', $item['extension'])->first();
                        $resolvedNs = app(ResellerNameserverService::class)->resolveForCustomerItem($user, $item);
                        $cloudflareDns = ! empty($item['cloudflare_dns'])
                            && app(DomainCloudflareDnsService::class)->isAvailableForCustomer($user);

                        // Create Domain
                        $domain = Domain::create([
                            'user_id' => $user->id,
                            'reseller_id' => $user->reseller_id,
                            'name' => $item['domain'],
                            'extension' => $item['extension'],
                            'status' => 'pending',
                            'nameserver_1' => $resolvedNs['ns1'],
                            'nameserver_2' => $resolvedNs['ns2'],
                            'nameserver_3' => $resolvedNs['ns3'],
                            'nameserver_4' => $resolvedNs['ns4'],
                            'cloudflare_dns_enabled' => $cloudflareDns,
                        ]);

                        $domainsCreatedByCartKey[$item['key']] = $domain->id;

                        // Get or create domain product
                        $domainProduct = Product::where('type', 'domain')->firstOrCreate(
                            ['type' => 'domain'],
                            [
                                'name' => 'Domain Registration',
                                'slug' => 'domain-registration',
                                'description' => 'Domain registration and renewal',
                                'category' => 'domains',
                                'price' => 0,
                                'billing_cycle' => 'annual',
                                'is_active' => true,
                                'visible_to_resellers' => false,
                            ]
                        );

                        // Create OrderItem
                        $orderItem = OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $domainProduct->id,
                            'description' => "{$item['domain']}{$item['extension']} ({$item['years']} year(s))",
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                            'billing_cycle' => 'annual',
                            'custom_options' => [
                                'domain' => $item['domain'],
                                'extension' => $item['extension'],
                                'years' => $item['years'],
                                'nameservers' => $resolvedNs,
                            ],
                        ]);

                        // Create Service for domain
                        $service = Service::create([
                            'user_id' => $user->id,
                            'product_id' => $domainProduct->id,
                            'order_item_id' => $orderItem->id,
                            'invoice_id' => $invoice->id,
                            'reseller_id' => $user->reseller_id,
                            'name' => "{$item['domain']}{$item['extension']}",
                            'status' => 'pending',
                            'billing_cycle' => 'annual',
                            'next_due_date' => now()->addDays($item['years'] * 365),
                            'service_meta' => [
                                'domain_id' => $domain->id,
                                'domain_name' => $item['domain'],
                                'extension' => $item['extension'],
                                'years' => $item['years'],
                                'nameservers' => $resolvedNs,
                                'cloudflare_dns' => $cloudflareDns,
                            ],
                        ]);

                        // Create InvoiceItem
                        InvoiceItem::create(array_merge([
                            'invoice_id' => $invoice->id,
                            'service_id' => $service->id,
                            'product_id' => $domainProduct->id,
                            'description' => "{$item['domain']}{$item['extension']} ({$item['years']} year(s))",
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                        ], $this->resellerDomainInvoiceItemFields($user, $domain, $invoice, $item)));
                    } elseif ($item['type'] === 'domain_transfer') {
                        $this->createDomainTransferCheckoutLine($user, $invoice, $item);
                    }
                }

                $this->addStorefrontPromoInvoiceItem($invoice, $promo);

                return $order;
            });

            // Clear cart
            SessionCart::clearActive();
            app(ResellerStorefrontPromoService::class)->forget();

            $invoice = $order->invoice ?? Invoice::find($order->invoice_id);

            if ($invoice) {
                try {
                    app(NotificationService::class)->notifyNewOrder($order, $invoice, 'awaiting payment');
                } catch (\Throwable $e) {
                    \Log::error('Failed to send new order notifications at checkout', [
                        'order_id' => $order->id,
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $paidWithCredits = $invoice
                ? $this->settleCheckoutInvoiceIfDue($invoice, $request)
                : false;

            $this->notifyPlatformDomainOrdersPlaced(
                $invoice?->fresh(['items']),
                $paidWithCredits ? 'credits' : 'awaiting payment',
            );

            if ($paidWithCredits) {
                return redirect()
                    ->route('customer.payment.success', $invoice)
                    ->with('success', 'Order placed successfully. Your services are being activated.');
            }

            return redirect()
                ->route('customer.payment.select-method', $invoice)
                ->with('success', 'Order placed successfully! Choose a payment method to activate your services.');
        } catch (\Exception $e) {
            \Log::error("Checkout failed: {$e->getMessage()}");

            return back()->with('error', 'Checkout failed: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{unit_price: float, setup_fee: float}
     */
    private function resolveCartItemPricing(Product $product, array $item, ?ResellerProduct $listing = null): array
    {
        if (Product::isServerType($product->type)) {
            return app(ServerProductConfigService::class)->priceForCartItem($product, $item, $listing);
        }

        // Reseller storefront / catalog retail must win over the platform shell product (often 0).
        if ($listing) {
            return [
                'unit_price' => $listing->priceForBillingCycle((string) ($item['billing_cycle'] ?? 'monthly')),
                'setup_fee' => (float) ($listing->setup_fee ?? 0),
            ];
        }

        return [
            'unit_price' => $this->getProductPrice($product, $item['billing_cycle'] ?? 'monthly'),
            'setup_fee' => (float) ($product->setup_fee ?? 0),
        ];
    }

    /**
     * Get product price based on billing cycle
     */
    private function getProductPrice(Product $product, string $billingCycle): float
    {
        return match ($billingCycle) {
            'monthly' => (float) $product->monthly_price,
            'quarterly' => ((float) $product->monthly_price * 3),
            'semi-annual' => ((float) $product->monthly_price * 6),
            'annual' => (float) ($product->yearly_price ?? ((float) $product->monthly_price * 12)),
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function prepareResellerProductCartItem(array $item): ?array
    {
        $resellerProduct = ResellerProduct::with('adminProduct')->find($item['reseller_product_id'] ?? null);
        if (! $resellerProduct || ! $resellerProduct->isOrderable()) {
            return null;
        }

        $customer = auth()->user();
        $hostReseller = $this->checkoutReseller();

        if ($customer?->reseller_id && $resellerProduct->reseller_id !== $customer->reseller_id) {
            return null;
        }

        if (! $customer && $hostReseller && $resellerProduct->reseller_id !== $hostReseller->id) {
            return null;
        }

        $product = $resellerProduct->provisionProduct();
        if (! $product) {
            return null;
        }

        $item['type'] = $product->type === 'shared_hosting' ? 'shared_hosting' : 'product';
        $item['product_id'] = $product->id;
        $item['reseller_id'] = $resellerProduct->reseller_id;
        $item['reseller_product_id'] = $resellerProduct->id;
        $item['name'] = $resellerProduct->name;
        $item['description'] = $resellerProduct->description ?? $resellerProduct->name;
        $pricing = $this->resolveCartItemPricing($product, $item, $resellerProduct);
        $item['unit_price'] = $pricing['unit_price'];
        $item['amount'] = $pricing['unit_price'] + $pricing['setup_fee'];

        if ($product->type === 'container_hosting' && $product->containerTemplate) {
            $item['container_template'] = $product->containerTemplate;
        }

        return $item;
    }

    private function isProductCheckoutItem(?string $type): bool
    {
        return in_array($type, ['product', 'shared_hosting'], true);
    }

    /**
     * Convert billing cycle to months
     */
    private function billingCycleMonths(string $cycle): int
    {
        return match ($cycle) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi-annual' => 6,
            'annual' => 12,
            default => 1,
        };
    }

    /**
     * Generate unique invoice number
     */
    private function generateInvoiceNumber(): string
    {
        return app(InvoiceNumberService::class)->nextDaily();
    }

    /**
     * Sync localStorage cart to session
     */
    public function syncCart(Request $request)
    {
        $cartItems = $request->input('cart', []);

        if (! is_array($cartItems)) {
            return response()->json(['error' => 'Invalid cart format'], 400);
        }

        // Enforce maximum cart size
        if (count($cartItems) > 20) {
            return response()->json(['error' => 'Cart cannot contain more than 20 items'], 422);
        }

        $allowedTypes = ['domain', 'hosting', 'vps', 'dedicated', 'container', 'product'];

        // Convert domain items to proper format — merge into existing cart (do not wipe portal items).
        $incoming = [];
        foreach ($cartItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            // Validate required keys are present
            if (! isset($item['type'])) {
                continue;
            }

            // Whitelist item types
            $itemType = $item['type'];
            if (! in_array($itemType, $allowedTypes, true)) {
                \Log::warning('syncCart: rejected unknown item type', [
                    'type' => $itemType,
                    'user_id' => auth()->id(),
                    'ip' => $request->ip(),
                ]);

                continue;
            }

            // Validate amount when present
            if (isset($item['price']) || isset($item['amount'])) {
                $amount = $item['price'] ?? $item['amount'];
                if (! is_numeric($amount) || (float) $amount < 0) {
                    return response()->json(['error' => 'Invalid item amount'], 422);
                }
            }

            $fullDomain = $item['full_domain'] ?? null;

            if ($itemType === 'domain' || $fullDomain) {
                // Domain from localStorage
                if ($fullDomain) {
                    $parts = explode('.', $fullDomain, 2);
                    $domain = $parts[0] ?? '';
                    $extension = '.'.($parts[1] ?? '');
                } else {
                    $domain = $item['domain'] ?? '';
                    $extension = $item['extension'] ?? '';
                }

                $incoming[] = [
                    'type' => 'domain',
                    'domain' => $domain,
                    'extension' => $extension,
                    'full_domain' => $fullDomain ?? ($domain.$extension),
                    'years' => $item['years'] ?? 1,
                    'price' => $item['price'] ?? 0,
                    'added_at' => now()->toIso8601String(),
                ];
            } else {
                $item['added_at'] = $item['added_at'] ?? now()->toIso8601String();
                $incoming[] = $item;
            }
        }

        $merged = SessionCart::mergeIncoming(SessionCart::active(), $incoming);

        if (count($merged) > 20) {
            return response()->json(['error' => 'Cart cannot contain more than 20 items'], 422);
        }

        SessionCart::putActive($merged);

        return response()->json(['success' => true, 'count' => count($merged)]);
    }

    /**
     * Show public checkout page (with optional account creation)
     */
    public function showPublic(Request $request)
    {
        $hostReseller = $this->checkoutReseller();
        $useBrandedCheckout = $hostReseller
            && app(ResellerLandingService::class)->isEnabled($hostReseller);

        // Authenticated customers on the platform (non-storefront) use the customer checkout UI.
        if (auth()->check() && ! $useBrandedCheckout) {
            return $this->show($request);
        }

        // Build cart from session
        $cartItems = [];
        $subtotal = 0;

        // Process cart items from session
        $sessionCart = SessionCart::active();
        foreach ($sessionCart as $key => $item) {
            $item['key'] = $key;

            $user = auth()->user();

            if ($item['type'] === 'product') {
                if (app(ResellerCustomerCatalogService::class)->isResellerCustomer($user)) {
                    continue;
                }

                $product = Product::find($item['product_id']);
                if (! $product) {
                    continue;
                }

                $item['name'] = $product->name;
                $pricing = $this->resolveCartItemPricing($product, $item);
                $item['unit_price'] = $pricing['unit_price'];
                $item['amount'] = $pricing['unit_price'] + $pricing['setup_fee'];
            } elseif ($item['type'] === 'reseller_product') {
                $prepared = $this->prepareResellerProductCartItem($item);
                if ($prepared === null) {
                    continue;
                }
                $item = $prepared;
            } elseif ($item['type'] === 'reseller_package') {
                $prepared = $this->prepareResellerPackageCartItem($item);
                if ($prepared === null) {
                    continue;
                }
                $item = $prepared;
            } elseif ($item['type'] === 'domain') {
                $extension = DomainExtension::where('extension', $item['extension'])->first();
                if (! $extension) {
                    continue;
                }

                $price = $this->domainRegistrationPrice($user, $extension, (int) ($item['years'] ?? 1));
                if ($price === null) {
                    continue;
                }

                $item['unit_price'] = $price;
                $item['amount'] = $item['unit_price'];
                $item['name'] = "{$item['domain']}{$item['extension']}";
            } elseif ($item['type'] === 'domain_transfer') {
                $prepared = $this->prepareDomainTransferCartItem($user, $item);
                if ($prepared === null) {
                    continue;
                }
                $item = $prepared;
            }

            $subtotal += $item['amount'];
            $cartItems[] = $item;
        }

        if (empty($cartItems)) {
            if ($useBrandedCheckout) {
                return redirect()
                    ->route('reseller.public.store.cart.show')
                    ->with('error', 'Your cart is empty');
            }

            return redirect('/')->with('error', 'Your cart is empty');
        }

        $promo = $this->storefrontPromoForCheckout($subtotal);
        $taxBreakdown = TaxService::calculateForUser($promo['taxable'], $user);

        $currency = app(UserCurrencyService::class)->model($user);
        $currencyCode = $currency->code;

        $branding = $hostReseller
            ? app(ResellerBrandingResolver::class)->forReseller($hostReseller)
            : app(ResellerBrandingResolver::class)->defaults();

        return view('public.checkout', [
            'cartItems' => $cartItems,
            'subtotal' => $subtotal,
            'discount' => $promo['discount'],
            'discountLabel' => $promo['label'],
            'promoCode' => $promo['code'],
            'tax' => $taxBreakdown['tax'],
            'taxEnabled' => $taxBreakdown['enabled'],
            'taxRate' => $taxBreakdown['rate'],
            'taxInclusive' => $taxBreakdown['inclusive'],
            'taxName' => $taxBreakdown['name'],
            'total' => $taxBreakdown['total'],
            'currency' => $currency,
            'currencyCode' => $currencyCode,
            'branding' => $branding,
            'isResellerStorefront' => (bool) $hostReseller,
            'cartUrl' => $hostReseller ? route('reseller.public.store.cart.show') : '/',
            'loginAtCheckoutUrl' => $hostReseller ? route('reseller.public.store.checkout.login') : null,
        ]);
    }

    /**
     * Process public checkout (create account then order or use authenticated user)
     */
    public function processPublic(Request $request)
    {
        try {
            $cart = SessionCart::active();

            if (empty($cart)) {
                return back()->with('error', 'Your cart is empty');
            }

            // If user is already authenticated, use their account
            if (auth()->check()) {
                $request->validate([
                    'agree_terms' => 'required|accepted',
                ]);

                return $this->processCheckout(auth()->user(), $cart, $request);
            }

            // For unauthenticated users, validate and create account
            $request->validate([
                'first_name' => 'required|string|max:127',
                'last_name' => 'nullable|string|max:127',
                'country' => ['required', 'string', 'size:2', new ValidCountryCode],
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:8|confirmed',
                'agree_terms' => 'required|accepted',
            ]);

            $displayName = app(RegistrationGuardService::class)->buildDisplayName(
                $request->input('first_name'),
                $request->input('last_name'),
            );

            // Create user account — do NOT auto-verify email; trigger normal verification flow
            $hostReseller = $this->checkoutReseller();
            $resellerId = $hostReseller?->id ?? session('registration_reseller_id');

            $user = User::create([
                'name' => $displayName,
                'country' => $request->country,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'email_verified_at' => null,
                'reseller_id' => $resellerId ?: null,
            ]);

            if ($resellerId) {
                session(['registration_reseller_id' => (int) $resellerId]);
            }

            app(UserCurrencyService::class)->syncFromCountry($user, true);

            try {
                app(EmailVerificationService::class)->sendVerificationCode($user);
            } catch (\Throwable $e) {
                \Log::warning('Checkout registration verification email failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Auth::login($user);

            // Now process the order using the authenticated user
            return $this->processCheckout($user, $cart, $request);
        } catch (\Exception $e) {
            \Log::error("Public checkout failed: {$e->getMessage()}");

            return back()->with('error', 'Checkout failed: '.$e->getMessage())->withInput();
        }
    }

    /**
     * Helper to process checkout for both authenticated and public users
     */
    private function processCheckout(User $user, array $cart, ?Request $request = null)
    {
        try {
            $resellerPackageItem = $this->findResellerPackageCartItem($cart);
            if ($resellerPackageItem !== null) {
                if (count($cart) > 1) {
                    throw new \InvalidArgumentException('Reseller package checkout cannot be combined with other items.');
                }

                return $this->processResellerPackageCheckout($user, $resellerPackageItem);
            }

            if (app(ResellerCustomerCatalogService::class)->isResellerCustomer($user)) {
                app(ResellerCheckoutGuardService::class)->assertCheckoutAllowed($user);
            }

            if ($request) {
                $request->validate([
                    'source_repo_url.*' => 'nullable|url|max:500',
                    'source_repo_branch.*' => 'nullable|string|max:120|regex:/^[A-Za-z0-9._\\/-]+$/',
                ]);
                app(SharedHostingCheckoutService::class)->validateCheckoutRequest($request, $cart);
                app(EmailHostingCheckoutService::class)->validateCheckoutRequest($request, $cart);
                app(ContainerEmailBundleService::class)->validateCheckoutRequest($request, $cart);
            }

            $order = \DB::transaction(function () use ($cart, $user, $request) {
                // Get cart items with details
                $cartItems = [];
                $subtotal = 0;

                foreach ($cart as $key => $item) {
                    $item['key'] = $key;

                    if ($item['type'] === 'product') {
                        if (app(ResellerCustomerCatalogService::class)->isResellerCustomer($user)) {
                            continue;
                        }

                        $product = Product::find($item['product_id']);
                        if (! $product) {
                            continue;
                        }

                        $pricing = $this->resolveCartItemPricing($product, $item);
                        $item['unit_price'] = $pricing['unit_price'];
                        $item['amount'] = $pricing['unit_price'] + $pricing['setup_fee'];
                    } elseif ($item['type'] === 'reseller_product') {
                        $prepared = $this->prepareResellerProductCartItem($item);
                        if ($prepared === null) {
                            continue;
                        }
                        $item = $prepared;
                    } elseif ($item['type'] === 'domain') {
                        $extension = DomainExtension::where('extension', $item['extension'])->first();
                        if (! $extension) {
                            continue;
                        }

                        $price = $this->domainRegistrationPrice($user, $extension, (int) ($item['years'] ?? 1));
                        if ($price === null) {
                            continue;
                        }

                        $item['unit_price'] = $price;
                        $item['amount'] = $item['unit_price'];
                    } elseif ($item['type'] === 'domain_transfer') {
                        $prepared = $this->prepareDomainTransferCartItem($user, $item);
                        if ($prepared === null) {
                            continue;
                        }
                        $item = $prepared;
                    }

                    $subtotal += $item['amount'];
                    $cartItems[] = $item;
                }

                if (empty($cartItems)) {
                    throw new \Exception('No valid items in cart');
                }

                $hostingCheckout = app(SharedHostingCheckoutService::class);
                $emailCheckout = app(EmailHostingCheckoutService::class);
                $cartItems = $hostingCheckout->sortCartItemsDomainsFirst($cartItems);
                $domainsCreatedByCartKey = [];

                $domainAddonTotal = $request
                    ? ($hostingCheckout->estimateDomainAddonTotal($request, $cart)
                        + $emailCheckout->estimateDomainAddonTotal($request, $cart)
                        + app(ContainerEmailBundleService::class)->estimateInvoiceAddonTotal($cart))
                    : 0.0;

                $subtotal += $domainAddonTotal;
                $promo = $this->storefrontPromoForCheckout($subtotal);
                $taxBreakdown = TaxService::calculateForUser($promo['taxable'], $user);

                // Create Invoice first (so we have the ID for the order)
                $invoice = Invoice::create([
                    'user_id' => $user->id,
                    'invoice_number' => $this->generateInvoiceNumber(),
                    'status' => 'unpaid',
                    'due_date' => now()->addDays((int) Setting::getValue('invoice_due_days', 30)),
                    'subtotal' => $taxBreakdown['subtotal'],
                    'tax' => $taxBreakdown['tax'],
                    'total' => $taxBreakdown['total'],
                ]);

                // Create Order linked to Invoice
                $order = Order::create([
                    'user_id' => $user->id,
                    'invoice_id' => $invoice->id,
                    'order_number' => 'ORD-'.uniqid(),
                    'status' => 'pending',
                    'payment_status' => 'unpaid',
                    'subtotal' => $taxBreakdown['subtotal'],
                    'tax' => $taxBreakdown['tax'],
                    'total' => $taxBreakdown['total'],
                ]);

                // Create OrderItems, Services, and Domains
                foreach ($cartItems as $item) {
                    if ($this->isProductCheckoutItem($item['type'] ?? null)) {
                        $product = Product::find($item['product_id']);

                        // Create OrderItem
                        $orderItem = OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'description' => $item['name'] ?? $product->name,
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                            'billing_cycle' => $item['billing_cycle'],
                            'custom_options' => [],
                        ]);

                        // Prepare service metadata and node for DirectAdmin
                        $serviceMeta = [];
                        $nodeId = null;

                        // For container products, collect environment variables, version, and database selection
                        if ($product->type === 'container_hosting' && $request) {
                            $envValuesKey = "env_values[{$item['key']}]";
                            $envValues = $request->input($envValuesKey, []);
                            if (! empty($envValues)) {
                                $serviceMeta['env_values'] = $envValues;
                            }

                            // Store selected version for templated containers
                            $selectedVersionKey = "selected_version[{$item['key']}]";
                            $selectedVersion = $request->input($selectedVersionKey);
                            if (! empty($selectedVersion)) {
                                $serviceMeta['selected_version'] = $selectedVersion;
                            }

                            // Store selected stack builder roles + database for provisioning
                            $serviceMeta = TechStackRoutingService::applySessionSelectionToServiceMeta($serviceMeta);

                            $serviceMeta = $this->applyResellerContainerServiceMeta($serviceMeta, $product, $user, $item);

                            $sourceRepoUrl = $request->input("source_repo_url.{$item['key']}");
                            if (! empty($sourceRepoUrl)) {
                                $serviceMeta['source_repo_url'] = $sourceRepoUrl;
                                $serviceMeta['source_repo_branch'] = $request->input("source_repo_branch.{$item['key']}", 'main');
                            }
                        }

                        if ($product->type === 'shared_hosting' && $product->provisioning_driver_key === 'directadmin' && $request) {
                            $resellerProduct = ! empty($item['reseller_product_id'])
                                ? ResellerProduct::find($item['reseller_product_id'])
                                : null;

                            $hostingContext = app(SharedHostingCheckoutService::class)->buildSharedHostingContext(
                                $request,
                                $item['key'],
                                $user,
                                $product,
                                $invoice,
                                $order,
                                $resellerProduct,
                                $cart,
                                $domainsCreatedByCartKey,
                            );
                            $serviceMeta = array_merge($serviceMeta, $hostingContext['service_meta']);
                            $nodeId = $hostingContext['node_id'];
                            $serviceMeta = TechStackRoutingService::applySessionSelectionToServiceMeta($serviceMeta);
                            app(SharedHostingCheckoutService::class)->persistExtraInvoiceItems(
                                $invoice,
                                $order,
                                $hostingContext['invoice_items']
                            );
                        }

                        if ($product->type === 'email_hosting' && $request) {
                            $emailContext = app(EmailHostingCheckoutService::class)->buildEmailHostingContext(
                                $request,
                                $item['key'],
                                $user,
                                $product,
                                $invoice,
                                $order,
                                $cart,
                                $domainsCreatedByCartKey,
                            );
                            $serviceMeta = array_merge($serviceMeta, $emailContext['service_meta']);
                            $nodeId = $emailContext['node_id'];
                        }

                        // For server types, capture OS and IP count from cart item
                        if (Product::isServerType($product->type)) {
                            if (! empty($item['operating_system'])) {
                                $serviceMeta['operating_system'] = $item['operating_system'];
                            }
                            if (! empty($item['ip_count'])) {
                                $serviceMeta['ip_count'] = (int) $item['ip_count'];
                            }
                            if (! empty($item['location_key'])) {
                                $serviceMeta['location_key'] = $item['location_key'];
                            }
                            if (! empty($item['location_name'])) {
                                $serviceMeta['location_name'] = $item['location_name'];
                            }
                            if (! empty($item['location_city'])) {
                                $serviceMeta['location_city'] = $item['location_city'];
                            }
                        }

                        if ($product->type === 'container_hosting') {
                            $domainHint = $serviceMeta['primary_domain']
                                ?? ($request ? $request->input("primary_domain.{$item['key']}") : null)
                                ?? ($request ? $request->input("domain.{$item['key']}") : null)
                                ?? null;

                            $projectBundle = app(ProjectHostingCheckoutService::class)->createFromCartItem(
                                $user,
                                $product,
                                $order,
                                $orderItem,
                                $invoice,
                                $item,
                                $serviceMeta,
                                is_string($domainHint) ? $domainHint : null,
                            );

                            if ($projectBundle !== null) {
                                if ($request) {
                                    app(ContainerEmailBundleService::class)->attachToContainerService(
                                        $request,
                                        $item['key'],
                                        $user,
                                        $product,
                                        $projectBundle['billing_service'],
                                        $invoice,
                                        $order,
                                        $item,
                                    );
                                }

                                continue;
                            }
                        }

                        // Create Service
                        $service = Service::create([
                            'user_id' => $user->id,
                            'product_id' => $product->id,
                            'order_item_id' => $orderItem->id,
                            'invoice_id' => $invoice->id,
                            'reseller_id' => $item['reseller_id'] ?? $user->reseller_id,
                            'name' => $item['name'] ?? $product->name,
                            'status' => 'pending',
                            'billing_cycle' => $item['billing_cycle'],
                            'custom_price' => $item['unit_price'],
                            'next_due_date' => now()->addMonths($this->billingCycleMonths($item['billing_cycle'])),
                            'provisioning_driver_key' => $product->provisioning_driver_key,
                            'node_id' => $nodeId,
                            'service_meta' => $serviceMeta,
                        ]);

                        if ($product->type === 'container_hosting') {
                            app(CustomerProjectService::class)->syncRelated($service->fresh());
                        }

                        if ($product->type === 'container_hosting' && $request) {
                            app(ContainerEmailBundleService::class)->attachToContainerService(
                                $request,
                                $item['key'],
                                $user,
                                $product,
                                $service,
                                $invoice,
                                $order,
                                $item,
                            );
                        }

                        // Create InvoiceItem
                        InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'service_id' => $service->id,
                            'product_id' => $product->id,
                            'description' => $item['name'] ?? $product->name,
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                        ]);
                    } elseif ($item['type'] === 'domain') {
                        $extension = DomainExtension::where('extension', $item['extension'])->first();
                        $resolvedNs = app(ResellerNameserverService::class)->resolveForCustomerItem($user, $item);
                        $cloudflareDns = ! empty($item['cloudflare_dns'])
                            && app(DomainCloudflareDnsService::class)->isAvailableForCustomer($user);

                        // Create Domain
                        $domain = Domain::create([
                            'user_id' => $user->id,
                            'reseller_id' => $user->reseller_id,
                            'name' => $item['domain'],
                            'extension' => $item['extension'],
                            'status' => 'pending',
                            'nameserver_1' => $resolvedNs['ns1'],
                            'nameserver_2' => $resolvedNs['ns2'],
                            'nameserver_3' => $resolvedNs['ns3'],
                            'nameserver_4' => $resolvedNs['ns4'],
                            'cloudflare_dns_enabled' => $cloudflareDns,
                        ]);

                        $domainsCreatedByCartKey[$item['key']] = $domain->id;

                        // Get or create domain product
                        $domainProduct = Product::where('type', 'domain')->firstOrCreate(
                            ['type' => 'domain'],
                            [
                                'name' => 'Domain Registration',
                                'slug' => 'domain-registration',
                                'description' => 'Domain registration and renewal',
                                'category' => 'domains',
                                'price' => 0,
                                'billing_cycle' => 'annual',
                                'is_active' => true,
                                'visible_to_resellers' => false,
                            ]
                        );

                        // Create OrderItem
                        $orderItem = OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $domainProduct->id,
                            'description' => "{$item['domain']}{$item['extension']} ({$item['years']} year(s))",
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                            'billing_cycle' => 'annual',
                            'custom_options' => [
                                'domain' => $item['domain'],
                                'extension' => $item['extension'],
                                'years' => $item['years'],
                                'nameservers' => $resolvedNs,
                            ],
                        ]);

                        // Create Service for domain
                        $service = Service::create([
                            'user_id' => $user->id,
                            'product_id' => $domainProduct->id,
                            'order_item_id' => $orderItem->id,
                            'invoice_id' => $invoice->id,
                            'reseller_id' => $user->reseller_id,
                            'name' => "{$item['domain']}{$item['extension']}",
                            'status' => 'pending',
                            'billing_cycle' => 'annual',
                            'next_due_date' => now()->addDays($item['years'] * 365),
                            'service_meta' => [
                                'domain_id' => $domain->id,
                                'domain_name' => $item['domain'],
                                'extension' => $item['extension'],
                                'years' => $item['years'],
                                'nameservers' => $resolvedNs,
                                'cloudflare_dns' => $cloudflareDns,
                            ],
                        ]);

                        // Create InvoiceItem
                        InvoiceItem::create(array_merge([
                            'invoice_id' => $invoice->id,
                            'service_id' => $service->id,
                            'product_id' => $domainProduct->id,
                            'description' => "{$item['domain']}{$item['extension']} ({$item['years']} year(s))",
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                        ], $this->resellerDomainInvoiceItemFields($user, $domain, $invoice, $item)));
                    } elseif ($item['type'] === 'domain_transfer') {
                        $this->createDomainTransferCheckoutLine($user, $invoice, $item);
                    }
                }

                $this->addStorefrontPromoInvoiceItem($invoice, $promo);

                return $order;
            });

            // Clear cart
            SessionCart::clearActive();
            app(ResellerStorefrontPromoService::class)->forget();

            $invoice = $order->invoice ?? Invoice::find($order->invoice_id);

            if ($invoice) {
                try {
                    app(NotificationService::class)->notifyNewOrder($order, $invoice, 'awaiting payment');
                } catch (\Throwable $e) {
                    \Log::error('Failed to send new order notifications at checkout', [
                        'order_id' => $order->id,
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $paidWithCredits = $invoice && $request
                ? $this->settleCheckoutInvoiceIfDue($invoice, $request)
                : false;

            $this->notifyPlatformDomainOrdersPlaced(
                $invoice?->fresh(['items']),
                $paidWithCredits ? 'credits' : 'awaiting payment',
            );

            if ($paidWithCredits) {
                return redirect()
                    ->route('customer.payment.success', $invoice)
                    ->with('success', 'Account created and order completed. Your services are being activated.');
            }

            return redirect()
                ->route('customer.payment.select-method', $invoice)
                ->with('success', 'Account created and order placed! Choose a payment method to activate your services.');
        } catch (\Exception $e) {
            \Log::error("Checkout processing failed: {$e->getMessage()}");

            return back()->with('error', 'Checkout failed: '.$e->getMessage());
        }
    }

    private function settleCheckoutInvoiceIfDue(Invoice $invoice, Request $request): bool
    {
        if ($this->applyCheckoutCreditsIfRequested($invoice, $request)) {
            return true;
        }

        $invoice->refresh();

        if ($invoice->getAmountRemaining() > 0) {
            return false;
        }

        return app(InvoiceSettlementService::class)->settleFullyPaid($invoice->fresh());
    }

    private function applyCheckoutCreditsIfRequested(Invoice $invoice, Request $request): bool
    {
        if (! $request->boolean('apply_credits')) {
            return false;
        }

        $settlement = app(InvoiceSettlementService::class);

        return $settlement->settleFromCredits($invoice->fresh());
    }

    private function notifyPlatformDomainOrdersPlaced(?Invoice $invoice, string $paymentMethod = 'awaiting payment'): void
    {
        if (! $invoice) {
            return;
        }

        $invoice->loadMissing('items');

        foreach ($invoice->items as $item) {
            $orderId = $item->custom_options['domain_order_id'] ?? null;
            if (! $orderId) {
                continue;
            }

            $domainOrder = ResellerDomainOrder::find($orderId);
            if (! $domainOrder?->isPlatformOrder()) {
                continue;
            }

            try {
                app(NotificationService::class)->notifyAdminResellerDomainOrder($domainOrder, 'placed', $paymentMethod);
            } catch (\Throwable $e) {
                \Log::error('Failed to notify admins about platform domain order', [
                    'domain_order_id' => $domainOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function domainRegistrationPrice(?User $user, DomainExtension $extension, int $years): ?float
    {
        $hostReseller = $this->checkoutReseller();

        if ($hostReseller && ($user === null || $user->reseller_id === $hostReseller->id)) {
            return app(ResellerPublicApiService::class)->retailPrice($hostReseller, $extension, $years);
        }

        return app(ResellerCustomerCatalogService::class)->domainRegistrationPrice($user, $extension, $years);
    }

    private function domainTransferPrice(?User $user, DomainExtension $extension): ?float
    {
        $hostReseller = $this->checkoutReseller();

        if ($hostReseller && ($user === null || $user->reseller_id === $hostReseller->id)) {
            return app(ResellerPublicApiService::class)->transferRetailPrice($hostReseller, $extension);
        }

        return app(ResellerCustomerCatalogService::class)->domainTransferPrice($user ?? new User, $extension);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function prepareDomainTransferCartItem(?User $user, array $item): ?array
    {
        $extension = DomainExtension::where('extension', $item['extension'] ?? '')->first();

        if (! $extension) {
            return null;
        }

        $price = $this->domainTransferPrice($user, $extension);

        if ($price === null || $price <= 0) {
            return null;
        }

        $item['unit_price'] = $price;
        $item['amount'] = $price;
        $item['name'] = ($item['domain'] ?? '').($item['extension'] ?? '');
        $item['description'] = 'Domain transfer';

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createDomainTransferCheckoutLine(User $user, Invoice $invoice, array $item): void
    {
        $resolvedNs = app(ResellerNameserverService::class)->resolveForCustomerItem($user, $item);

        $domain = DomainTransferService::createTransferRequest(
            $user,
            (string) $item['domain'],
            (string) $item['extension'],
            (string) $item['epp_code'],
            (string) $item['old_registrar'],
            $item['old_registrar_url'] ?? null,
        );

        $domain->update([
            'nameserver_1' => $resolvedNs['ns1'],
            'nameserver_2' => $resolvedNs['ns2'],
            'nameserver_3' => $resolvedNs['ns3'],
            'nameserver_4' => $resolvedNs['ns4'],
        ]);

        $invoiceItemData = [
            'invoice_id' => $invoice->id,
            'domain_id' => $domain->id,
            'product_type' => 'Domain',
            'description' => "Domain Transfer: {$item['domain']}{$item['extension']}",
            'quantity' => 1,
            'unit_price' => $item['unit_price'],
            'amount' => $item['amount'],
            'custom_options' => [
                'type' => 'domain_transfer',
                'domain_id' => $domain->id,
            ],
        ];

        $domainOrder = app(ResellerDomainOrderService::class)->createForTransferCheckout(
            $user,
            $domain,
            $invoice,
            (string) $item['domain'],
            (string) $item['extension'],
            (float) $item['amount'],
        );

        if ($domainOrder) {
            $invoiceItemData = array_merge(
                $invoiceItemData,
                app(ResellerDomainOrderService::class)->invoiceItemAttributes($domainOrder),
            );
        }

        InvoiceItem::create($invoiceItemData);
    }

    private function checkoutReseller(): ?User
    {
        if (! app()->bound('currentReseller')) {
            return null;
        }

        return app('currentReseller');
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>|null
     */
    private function findResellerPackageCartItem(array $cart): ?array
    {
        foreach ($cart as $item) {
            if (($item['type'] ?? null) === 'reseller_package') {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function prepareResellerPackageCartItem(array $item): ?array
    {
        if ($this->checkoutReseller() !== null) {
            return null;
        }

        $package = ResellerPackage::query()
            ->where('id', $item['reseller_package_id'] ?? null)
            ->where('active', true)
            ->first();

        if (! $package) {
            return null;
        }

        $amounts = app(ResellerPackageSubscriptionService::class)->calculateAmounts((float) $package->price);

        return array_merge($item, [
            'type' => 'reseller_package',
            'reseller_package_id' => $package->id,
            'name' => $package->name,
            'description' => $package->description ?: 'Reseller hosting plan',
            'billing_cycle' => $package->billing_cycle,
            'unit_price' => $amounts['subtotal'],
            'amount' => $amounts['total'],
        ]);
    }

    private function processResellerPackageCheckout(User $user, array $item)
    {
        $package = ResellerPackage::query()
            ->where('id', $item['reseller_package_id'] ?? null)
            ->where('active', true)
            ->first();

        if (! $package) {
            return back()->with('error', 'This reseller package is no longer available.');
        }

        if ($user->reseller_package_id === $package->id) {
            return back()->with('info', 'You are already subscribed to this package.');
        }

        if ($user->resellerPackage && (float) $package->price < (float) $user->resellerPackage->price) {
            return back()->with('error', 'You cannot downgrade to a lower-tier reseller package.');
        }

        if (! $user->is_reseller) {
            $user->is_reseller = true;
            $user->save();
        }

        $subscriptions = app(ResellerPackageSubscriptionService::class);
        $pending = $subscriptions->pendingSubscriptionInvoice($user, $package);

        if ($pending) {
            SessionCart::clearPortal();

            return redirect()
                ->route('reseller.payment.select-method', $pending)
                ->with('info', 'Complete payment for invoice #'.$pending->invoice_number.' to activate this plan.');
        }

        $invoice = $subscriptions->createSubscriptionInvoice($user, $package);
        SessionCart::clearPortal();

        if ($invoice->isPaid()) {
            return redirect()
                ->route('reseller.packages.index')
                ->with('success', 'Your reseller plan is now active.');
        }

        return redirect()
            ->route('reseller.payment.select-method', $invoice)
            ->with('success', 'Invoice #'.$invoice->invoice_number.' created. Complete payment to activate your reseller plan.');
    }

    private function resellerDomainInvoiceItemFields(User $user, Domain $domain, Invoice $invoice, array $item): array
    {
        $domainOrder = app(ResellerDomainOrderService::class)->createForCustomerCheckout(
            $user,
            $domain,
            $invoice,
            $item['domain'],
            $item['extension'],
            (int) $item['years'],
            (float) $item['amount'],
        );

        return app(ResellerDomainOrderService::class)->invoiceItemAttributes($domainOrder);
    }

    /**
     * @param  array<string, mixed>  $serviceMeta
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function applyResellerContainerServiceMeta(
        array $serviceMeta,
        Product $product,
        User $user,
        array $item,
    ): array {
        if ($product->type !== 'container_hosting' || empty($item['reseller_product_id'])) {
            return $serviceMeta;
        }

        $catalogProduct = ResellerProduct::find($item['reseller_product_id']);
        $reseller = User::find($item['reseller_id'] ?? $user->reseller_id);
        if (! $catalogProduct || ! $reseller) {
            return $serviceMeta;
        }

        $hostingContext = app(ResellerHostingSetupService::class)->buildProvisioningContext(
            $reseller,
            $user,
            $product,
            null,
            $catalogProduct,
        );

        return array_merge($serviceMeta, $hostingContext['service_meta']);
    }

    /**
     * @return array{discount: float, code: string|null, label: string|null, taxable: float}
     */
    private function storefrontPromoForCheckout(float $subtotal): array
    {
        $hostReseller = $this->checkoutReseller();
        if (! $hostReseller || ! app(ResellerLandingService::class)->isEnabled($hostReseller)) {
            return [
                'discount' => 0.0,
                'code' => null,
                'label' => null,
                'taxable' => $subtotal,
            ];
        }

        $promo = app(ResellerStorefrontPromoService::class)->resolve($hostReseller, $subtotal);

        return [
            'discount' => $promo['discount'],
            'code' => $promo['code'],
            'label' => $promo['label'],
            'taxable' => max(0.0, $subtotal - $promo['discount']),
        ];
    }

    /**
     * @param  array{discount: float, code: string|null, label: string|null, taxable?: float}  $promo
     */
    private function addStorefrontPromoInvoiceItem(Invoice $invoice, array $promo): void
    {
        if (($promo['discount'] ?? 0) <= 0) {
            return;
        }

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Promo'.(! empty($promo['code']) ? ': '.$promo['code'] : ''),
            'quantity' => 1,
            'unit_price' => -1 * (float) $promo['discount'],
            'amount' => -1 * (float) $promo['discount'],
            'custom_options' => [
                'promo_code' => $promo['code'] ?? null,
            ],
        ]);
    }
}
