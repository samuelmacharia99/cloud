<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Checkout\SharedHostingCheckoutService;
use App\Services\NodeNameserverService;
use App\Services\ResellerCheckoutGuardService;
use App\Services\ResellerCustomerCatalogService;
use App\Services\ResellerDomainOrderService;
use App\Services\ResellerHostingSetupService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class CheckoutController extends Controller
{
    const CART_SESSION_KEY = 'cart';

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

            // Get currency info
            $currencyCode = Setting::getValue('currency', 'KES');
            $currency = Currency::where('code', $currencyCode)->where('is_active', true)->first();

            return view('customer.checkout.invoice', [
                'invoice' => $invoice,
                'user' => auth()->user(),
                'currency' => $currency,
                'currencyCode' => $currencyCode,
            ]);
        }

        // Get cart from session and localStorage (domains)
        $cart = session(self::CART_SESSION_KEY, []);

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
                $item['unit_price'] = $this->getProductPrice($product, $item['billing_cycle']);
                $item['amount'] = $item['unit_price'];

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
            }

            $subtotal += $item['amount'];
            $cartItems[] = $item;
        }

        if (empty($cartItems)) {
            return redirect()->route('customer.cart.index')->with('error', 'No valid items in cart');
        }

        // Calculate tax
        $taxEnabled = Setting::getValue('tax_enabled') == 'true';
        $taxRate = (float) Setting::getValue('tax_rate', 0);
        $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
        $total = $subtotal + $tax;

        // Get currency info
        $currencyCode = Setting::getValue('currency', 'KES');
        $currency = Currency::where('code', $currencyCode)->where('is_active', true)->first();

        $sharedHostingItems = array_values(array_filter(
            $cartItems,
            fn ($item) => ($item['type'] ?? null) === 'shared_hosting'
        ));

        $domainExtensions = DomainExtension::where('enabled', true)->orderBy('extension')->get();
        $defaultNameservers = app(NodeNameserverService::class)->platformDefaults();

        return view('customer.checkout.index', [
            'cartItems' => $cartItems,
            'sharedHostingItems' => $sharedHostingItems,
            'domainExtensions' => $domainExtensions,
            'defaultNameservers' => $defaultNameservers,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'taxEnabled' => $taxEnabled,
            'taxRate' => $taxRate,
            'total' => $total,
            'user' => auth()->user(),
            'currency' => $currency,
            'currencyCode' => $currencyCode,
        ]);
    }

    /**
     * Process checkout and create order
     */
    public function process(Request $request)
    {
        $request->validate([
            'agree_terms' => 'required|accepted',
            'source_repo_url.*' => 'nullable|url|max:500',
            'source_repo_branch.*' => 'nullable|string|max:120|regex:/^[A-Za-z0-9._\\/-]+$/',
        ]);

        $cart = session(self::CART_SESSION_KEY, []);

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

                        $price = $this->getProductPrice($product, $item['billing_cycle']);
                        $item['unit_price'] = $price;
                        $item['amount'] = $price;
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
                    }

                    $subtotal += $item['amount'];
                    $cartItems[] = $item;
                }

                if (empty($cartItems)) {
                    throw new \Exception('No valid items in cart');
                }

                $domainAddonTotal = app(SharedHostingCheckoutService::class)->estimateDomainAddonTotal($request, $cart);

                // Calculate totals
                $taxEnabled = Setting::getValue('tax_enabled') == 'true';
                $taxRate = (float) Setting::getValue('tax_rate', 0);
                $subtotal += $domainAddonTotal;
                $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
                $total = $subtotal + $tax;

                // Create Invoice first (so we have the ID for the order)
                $invoice = Invoice::create([
                    'user_id' => $user->id,
                    'invoice_number' => $this->generateInvoiceNumber(),
                    'status' => 'unpaid',
                    'due_date' => now()->addDays((int) Setting::getValue('invoice_due_days', 30)),
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                ]);

                // Create Order linked to Invoice
                $order = Order::create([
                    'user_id' => $user->id,
                    'invoice_id' => $invoice->id,
                    'order_number' => 'ORD-'.uniqid(),
                    'status' => 'pending',
                    'payment_status' => 'unpaid',
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
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

                            // Store selected database for provisioning
                            $techstack = session('selected_techstack', []);
                            if (! empty($techstack['database_id'])) {
                                $serviceMeta['database_id'] = (int) $techstack['database_id'];
                            }

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
                            );
                            $serviceMeta = array_merge($serviceMeta, $hostingContext['service_meta']);
                            $nodeId = $hostingContext['node_id'];
                            app(SharedHostingCheckoutService::class)->persistExtraInvoiceItems(
                                $invoice,
                                $order,
                                $hostingContext['invoice_items']
                            );
                        }

                        // For server types, capture OS and IP count from cart item
                        if (Product::isServerType($product->type)) {
                            if (! empty($item['operating_system'])) {
                                $serviceMeta['operating_system'] = $item['operating_system'];
                            }
                            if (! empty($item['ip_count'])) {
                                $serviceMeta['ip_count'] = (int) $item['ip_count'];
                            }
                        }

                        // Determine provisioning driver
                        $provisioningDriver = $product->provisioning_driver_key;
                        if (! $provisioningDriver && Product::isServerType($product->type)) {
                            $provisioningDriver = 'server';
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
                        $ns = $item['nameservers'] ?? [];
                        $platformNs = app(NodeNameserverService::class)->platformDefaults();

                        // Create Domain
                        $domain = Domain::create([
                            'user_id' => $user->id,
                            'name' => $item['domain'],
                            'extension' => $item['extension'],
                            'status' => 'pending',
                            'nameserver_1' => $ns['ns1'] ?? $platformNs['ns1'],
                            'nameserver_2' => $ns['ns2'] ?? $platformNs['ns2'],
                            'nameserver_3' => $ns['ns3'] ?? $platformNs['ns3'],
                            'nameserver_4' => $ns['ns4'] ?? $platformNs['ns4'],
                        ]);

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
                                'nameservers' => $ns,
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
                                'nameservers' => $ns,
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
                    }
                }

                return $order;
            });

            // Clear cart
            session([self::CART_SESSION_KEY => []]);

            // Get the invoice that was just created
            $invoice = Invoice::where('user_id', $user->id)->latest()->first();

            return redirect()
                ->route('customer.invoices.show', $invoice)
                ->with('success', 'Order placed successfully! Please pay your invoice to activate services.');
        } catch (\Exception $e) {
            \Log::error("Checkout failed: {$e->getMessage()}");

            return back()->with('error', 'Checkout failed: '.$e->getMessage());
        }
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
        if ($customer?->reseller_id && $resellerProduct->reseller_id !== $customer->reseller_id) {
            return null;
        }

        $product = $resellerProduct->provisionProduct();
        if (! $product) {
            return null;
        }

        $unitPrice = $resellerProduct->priceForBillingCycle($item['billing_cycle']);
        $item['type'] = $product->type === 'shared_hosting' ? 'shared_hosting' : 'product';
        $item['product_id'] = $product->id;
        $item['reseller_id'] = $resellerProduct->reseller_id;
        $item['reseller_product_id'] = $resellerProduct->id;
        $item['name'] = $resellerProduct->name;
        $item['description'] = $resellerProduct->description ?? $resellerProduct->name;
        $item['unit_price'] = $unitPrice;
        $item['amount'] = $unitPrice + (float) ($resellerProduct->setup_fee ?? 0);

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
        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $date = now()->format('Ymd');
        $count = Invoice::whereDate('created_at', now())->count() + 1;

        return "{$prefix}-{$date}-".str_pad($count, 5, '0', STR_PAD_LEFT);
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

        // Convert domain items to proper format
        $processedCart = [];
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

                $processedCart[] = [
                    'type' => 'domain',
                    'domain' => $domain,
                    'extension' => $extension,
                    'full_domain' => $fullDomain ?? ($domain.$extension),
                    'years' => $item['years'] ?? 1,
                    'price' => $item['price'] ?? 0,
                ];
            } else {
                // Other item types
                $processedCart[] = $item;
            }
        }

        session([self::CART_SESSION_KEY => $processedCart]);

        return response()->json(['success' => true, 'count' => count($processedCart)]);
    }

    /**
     * Show public checkout page (with optional account creation)
     */
    public function showPublic(Request $request)
    {
        // If user is authenticated, use regular checkout
        if (auth()->check()) {
            return $this->show();
        }

        // Build cart from session
        $cartItems = [];
        $subtotal = 0;

        // Process cart items from session
        $sessionCart = session(self::CART_SESSION_KEY, []);
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
                $item['unit_price'] = $this->getProductPrice($product, $item['billing_cycle']);
                $item['amount'] = $item['unit_price'];
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
                $item['name'] = "{$item['domain']}{$item['extension']}";
            }

            $subtotal += $item['amount'];
            $cartItems[] = $item;
        }

        if (empty($cartItems)) {
            return redirect('/')->with('error', 'Your cart is empty');
        }

        // Calculate tax
        $taxEnabled = Setting::getValue('tax_enabled') == 'true';
        $taxRate = (float) Setting::getValue('tax_rate', 0);
        $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
        $total = $subtotal + $tax;

        // Get currency info
        $currencyCode = Setting::getValue('currency', 'KES');
        $currency = Currency::where('code', $currencyCode)->where('is_active', true)->first();

        return view('public.checkout', [
            'cartItems' => $cartItems,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'taxEnabled' => $taxEnabled,
            'taxRate' => $taxRate,
            'total' => $total,
            'currency' => $currency,
            'currencyCode' => $currencyCode,
        ]);
    }

    /**
     * Process public checkout (create account then order or use authenticated user)
     */
    public function processPublic(Request $request)
    {
        try {
            $cart = session(self::CART_SESSION_KEY, []);

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
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:8|confirmed',
                'agree_terms' => 'required|accepted',
            ]);

            // Create user account — do NOT auto-verify email; trigger normal verification flow
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'email_verified_at' => null,
            ]);

            // Fire Registered event so the default email verification notification is sent
            event(new Registered($user));

            // Log the user in
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
            if (app(ResellerCustomerCatalogService::class)->isResellerCustomer($user)) {
                app(ResellerCheckoutGuardService::class)->assertCheckoutAllowed($user);
            }

            if ($request) {
                $request->validate([
                    'source_repo_url.*' => 'nullable|url|max:500',
                    'source_repo_branch.*' => 'nullable|string|max:120|regex:/^[A-Za-z0-9._\\/-]+$/',
                ]);
                app(SharedHostingCheckoutService::class)->validateCheckoutRequest($request, $cart);
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

                        $price = $this->getProductPrice($product, $item['billing_cycle']);
                        $item['unit_price'] = $price;
                        $item['amount'] = $price;
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
                    }

                    $subtotal += $item['amount'];
                    $cartItems[] = $item;
                }

                if (empty($cartItems)) {
                    throw new \Exception('No valid items in cart');
                }

                $domainAddonTotal = $request
                    ? app(SharedHostingCheckoutService::class)->estimateDomainAddonTotal($request, $cart)
                    : 0.0;

                // Calculate totals
                $taxEnabled = Setting::getValue('tax_enabled') == 'true';
                $taxRate = (float) Setting::getValue('tax_rate', 0);
                $subtotal += $domainAddonTotal;
                $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
                $total = $subtotal + $tax;

                // Create Invoice first (so we have the ID for the order)
                $invoice = Invoice::create([
                    'user_id' => $user->id,
                    'invoice_number' => $this->generateInvoiceNumber(),
                    'status' => 'unpaid',
                    'due_date' => now()->addDays((int) Setting::getValue('invoice_due_days', 30)),
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                ]);

                // Create Order linked to Invoice
                $order = Order::create([
                    'user_id' => $user->id,
                    'invoice_id' => $invoice->id,
                    'order_number' => 'ORD-'.uniqid(),
                    'status' => 'pending',
                    'payment_status' => 'unpaid',
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
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

                            // Store selected database for provisioning
                            $techstack = session('selected_techstack', []);
                            if (! empty($techstack['database_id'])) {
                                $serviceMeta['database_id'] = (int) $techstack['database_id'];
                            }

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
                            );
                            $serviceMeta = array_merge($serviceMeta, $hostingContext['service_meta']);
                            $nodeId = $hostingContext['node_id'];
                            app(SharedHostingCheckoutService::class)->persistExtraInvoiceItems(
                                $invoice,
                                $order,
                                $hostingContext['invoice_items']
                            );
                        }

                        // For server types, capture OS and IP count from cart item
                        if (Product::isServerType($product->type)) {
                            if (! empty($item['operating_system'])) {
                                $serviceMeta['operating_system'] = $item['operating_system'];
                            }
                            if (! empty($item['ip_count'])) {
                                $serviceMeta['ip_count'] = (int) $item['ip_count'];
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
                        $ns = $item['nameservers'] ?? [];
                        $platformNs = app(NodeNameserverService::class)->platformDefaults();

                        // Create Domain
                        $domain = Domain::create([
                            'user_id' => $user->id,
                            'name' => $item['domain'],
                            'extension' => $item['extension'],
                            'status' => 'pending',
                            'nameserver_1' => $ns['ns1'] ?? $platformNs['ns1'],
                            'nameserver_2' => $ns['ns2'] ?? $platformNs['ns2'],
                            'nameserver_3' => $ns['ns3'] ?? $platformNs['ns3'],
                            'nameserver_4' => $ns['ns4'] ?? $platformNs['ns4'],
                        ]);

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
                                'nameservers' => $ns,
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
                                'nameservers' => $ns,
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
                    }
                }

                return $order;
            });

            // Clear cart
            session([self::CART_SESSION_KEY => []]);

            // Get the invoice that was just created
            $invoice = Invoice::where('user_id', $user->id)->latest()->first();

            return redirect()
                ->route('customer.invoices.show', $invoice)
                ->with('success', 'Account created and order placed! Please pay your invoice to activate services.');
        } catch (\Exception $e) {
            \Log::error("Checkout processing failed: {$e->getMessage()}");

            return back()->with('error', 'Checkout failed: '.$e->getMessage());
        }
    }

    private function domainRegistrationPrice(User $user, DomainExtension $extension, int $years): ?float
    {
        return app(ResellerCustomerCatalogService::class)->domainRegistrationPrice($user, $extension, $years);
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
}
