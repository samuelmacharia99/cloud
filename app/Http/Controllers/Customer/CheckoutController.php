<?php

namespace App\Http\Controllers\Customer;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

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

        // Prepare cart items with details
        $cartItems = [];
        $subtotal = 0;

        foreach ($cart as $key => $item) {
            $item['key'] = $key;

            if ($item['type'] === 'product') {
                $product = Product::find($item['product_id']);
                if (!$product) continue;

                $item['name'] = $product->name;
                $item['description'] = $product->description ?? $product->name;
                $item['type'] = $product->type;
                $item['unit_price'] = $this->getProductPrice($product, $item['billing_cycle']);
                $item['amount'] = $item['unit_price'];

                // Load container template if applicable
                if ($product->type === 'container_hosting' && $product->containerTemplate) {
                    $item['container_template'] = $product->containerTemplate;
                }
            } elseif ($item['type'] === 'domain') {
                $extension = DomainExtension::where('extension', $item['extension'])->first();
                if (!$extension) continue;

                $pricing = $extension->getRetailPricing($item['years']);
                $item['unit_price'] = $pricing ? (float) $pricing->price : 0;
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

        return view('customer.checkout.index', [
            'cartItems' => $cartItems,
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
        ]);

        $cart = session(self::CART_SESSION_KEY, []);

        if (empty($cart)) {
            return back()->with('error', 'Your cart is empty');
        }

        $user = auth()->user();

        try {
            $order = \DB::transaction(function () use ($cart, $user, $request) {
                // Get cart items with details
                $cartItems = [];
                $subtotal = 0;

                foreach ($cart as $key => $item) {
                    $item['key'] = $key;

                    if ($item['type'] === 'product') {
                        $product = Product::find($item['product_id']);
                        if (!$product) continue;

                        $price = $this->getProductPrice($product, $item['billing_cycle']);
                        $item['unit_price'] = $price;
                        $item['amount'] = $price;
                    } elseif ($item['type'] === 'domain') {
                        $extension = DomainExtension::where('extension', $item['extension'])->first();
                        if (!$extension) continue;

                        $pricing = $extension->getRetailPricing($item['years']);
                        $item['unit_price'] = $pricing ? (float) $pricing->price : 0;
                        $item['amount'] = $item['unit_price'];
                    }

                    $subtotal += $item['amount'];
                    $cartItems[] = $item;
                }

                if (empty($cartItems)) {
                    throw new \Exception('No valid items in cart');
                }

                // Calculate totals
                $taxEnabled = Setting::getValue('tax_enabled') == 'true';
                $taxRate = (float) Setting::getValue('tax_rate', 0);
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
                    'order_number' => 'ORD-' . uniqid(),
                    'status' => 'pending',
                    'payment_status' => 'unpaid',
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                ]);

                // Create OrderItems, Services, and Domains
                foreach ($cartItems as $item) {
                    if ($item['type'] === 'product') {
                        $product = Product::find($item['product_id']);

                        // Create OrderItem
                        $orderItem = OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'description' => $product->name,
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
                            if (!empty($envValues)) {
                                $serviceMeta['env_values'] = $envValues;
                            }

                            // Store selected version for templated containers
                            $selectedVersionKey = "selected_version[{$item['key']}]";
                            $selectedVersion = $request->input($selectedVersionKey);
                            if (!empty($selectedVersion)) {
                                $serviceMeta['selected_version'] = $selectedVersion;
                            }

                            // Store selected database for provisioning
                            $techstack = session('selected_techstack', []);
                            if (!empty($techstack['database_id'])) {
                                $serviceMeta['database_id'] = (int) $techstack['database_id'];
                            }
                        }

                        // For DirectAdmin shared hosting, prepare credentials and node assignment
                        if ($product->type === 'shared_hosting' && $product->provisioning_driver_key === 'directadmin') {
                            $daSetup = $this->setupDirectAdminService($product, $user);
                            $serviceMeta = array_merge($serviceMeta, $daSetup['meta']);
                            $nodeId = $daSetup['node_id'];
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
                            'name' => $product->name,
                            'status' => 'pending',
                            'billing_cycle' => $item['billing_cycle'],
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
                            'description' => $product->name,
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                        ]);
                    } elseif ($item['type'] === 'domain') {
                        $extension = DomainExtension::where('extension', $item['extension'])->first();

                        // Create Domain
                        $domain = Domain::create([
                            'user_id' => $user->id,
                            'name' => $item['domain'],
                            'extension' => $item['extension'],
                            'status' => 'pending',
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
                            ],
                        ]);

                        // Create Service for domain
                        $service = Service::create([
                            'user_id' => $user->id,
                            'product_id' => $domainProduct->id,
                            'order_item_id' => $orderItem->id,
                            'name' => "{$item['domain']}{$item['extension']}",
                            'status' => 'pending',
                            'billing_cycle' => 'annual',
                            'next_due_date' => now()->addDays($item['years'] * 365),
                            'service_meta' => [
                                'domain_id' => $domain->id,
                                'domain_name' => $item['domain'],
                                'extension' => $item['extension'],
                                'years' => $item['years'],
                            ],
                        ]);

                        // Create InvoiceItem
                        InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'service_id' => $service->id,
                            'product_id' => $domainProduct->id,
                            'description' => "{$item['domain']}{$item['extension']} ({$item['years']} year(s))",
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                        ]);
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
            return back()->with('error', 'Checkout failed: ' . $e->getMessage());
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

        return "{$prefix}-{$date}-" . str_pad($count, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Setup DirectAdmin service with node assignment and credentials
     */
    private function setupDirectAdminService(Product $product, User $user): array
    {
        // Find an active DirectAdmin node (prefer one with fewer services)
        $node = \App\Models\Node::where('type', 'directadmin')
            ->where('is_active', true)
            ->where('status', 'online')
            ->withCount('services')
            ->orderBy('services_count')
            ->first();

        if (!$node) {
            throw new \Exception('No active DirectAdmin nodes available for provisioning.');
        }

        // Generate username from customer name/email
        $baseUsername = $this->generateDirectAdminUsername($user);

        // Generate password (16 chars, complex)
        $password = $this->generateDirectAdminPassword();

        // Use customer's primary domain or generate one
        $domain = $user->email ? explode('@', $user->email)[0] . '.local' : $baseUsername . '.local';

        return [
            'node_id' => $node->id,
            'meta' => [
                'username' => $baseUsername,
                'password' => $password,
                'domain' => $domain,
                'node_id' => $node->id,
                'node_name' => $node->name,
            ],
        ];
    }

    /**
     * Generate DirectAdmin-safe username
     */
    private function generateDirectAdminUsername(User $user): string
    {
        // Use first part of email or customer name, sanitized to 16 chars, lowercase
        $base = explode('@', $user->email)[0] ?? Str::slug($user->name);
        $username = strtolower(Str::slug(substr($base, 0, 16), ''));

        // Ensure it's not already taken (shouldn't be in practice, but check anyway)
        $count = Service::where('service_meta->username', $username)->count();
        if ($count > 0) {
            $username = $username . substr(uniqid(), -3);
            $username = substr($username, 0, 16);
        }

        return $username;
    }

    /**
     * Generate DirectAdmin-safe password (16 chars, complex)
     */
    private function generateDirectAdminPassword(): string
    {
        $chars = [
            'lower' => 'abcdefghijkmnpqrstuvwxyz', // no o or l
            'upper' => 'ABCDEFGHJKMNPQRSTUVWXYZ', // no O or I
            'digit' => '23456789', // no 0 or 1
            'symbol' => '!@#$%^&*', // avoid ambiguous chars
        ];

        $password = '';
        $password .= $chars['lower'][rand(0, strlen($chars['lower']) - 1)];
        $password .= $chars['upper'][rand(0, strlen($chars['upper']) - 1)];
        $password .= $chars['digit'][rand(0, strlen($chars['digit']) - 1)];
        $password .= $chars['symbol'][rand(0, strlen($chars['symbol']) - 1)];

        // Fill rest with random chars
        for ($i = 4; $i < 16; $i++) {
            $all = $chars['lower'] . $chars['upper'] . $chars['digit'] . $chars['symbol'];
            $password .= $all[rand(0, strlen($all) - 1)];
        }

        // Shuffle to avoid predictable pattern
        $password = str_shuffle($password);

        return $password;
    }

    /**
     * Sync localStorage cart to session
     */
    public function syncCart(Request $request)
    {
        $cartItems = $request->input('cart', []);

        if (!is_array($cartItems)) {
            return response()->json(['error' => 'Invalid cart format'], 400);
        }

        // Convert domain items to proper format
        $processedCart = [];
        foreach ($cartItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemType = $item['type'] ?? null;
            $fullDomain = $item['full_domain'] ?? null;

            if ($itemType === 'domain' || $fullDomain) {
                // Domain from localStorage
                if ($fullDomain) {
                    $parts = explode('.', $fullDomain, 2);
                    $domain = $parts[0] ?? '';
                    $extension = '.' . ($parts[1] ?? '');
                } else {
                    $domain = $item['domain'] ?? '';
                    $extension = $item['extension'] ?? '';
                }

                $processedCart[] = [
                    'type' => 'domain',
                    'domain' => $domain,
                    'extension' => $extension,
                    'full_domain' => $fullDomain ?? ($domain . $extension),
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

            if ($item['type'] === 'product') {
                $product = Product::find($item['product_id']);
                if (!$product) continue;

                $item['name'] = $product->name;
                $item['unit_price'] = $this->getProductPrice($product, $item['billing_cycle']);
                $item['amount'] = $item['unit_price'];
            } elseif ($item['type'] === 'domain') {
                $extension = DomainExtension::where('extension', $item['extension'])->first();
                if (!$extension) continue;

                $pricing = $extension->getRetailPricing($item['years'] ?? 1);
                $item['unit_price'] = $pricing ? (float) $pricing->price : 0;
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

            // Create user account
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'email_verified_at' => now(), // Auto-verify for checkout
            ]);

            // Log the user in
            Auth::login($user);

            // Now process the order using the authenticated user
            return $this->processCheckout($user, $cart, $request);
        } catch (\Exception $e) {
            \Log::error("Public checkout failed: {$e->getMessage()}");
            return back()->with('error', 'Checkout failed: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Helper to process checkout for both authenticated and public users
     */
    private function processCheckout(User $user, array $cart, Request $request = null)
    {
        try {
            $order = \DB::transaction(function () use ($cart, $user, $request) {
                // Get cart items with details
                $cartItems = [];
                $subtotal = 0;

                foreach ($cart as $key => $item) {
                    $item['key'] = $key;

                    if ($item['type'] === 'product') {
                        $product = Product::find($item['product_id']);
                        if (!$product) continue;

                        $price = $this->getProductPrice($product, $item['billing_cycle']);
                        $item['unit_price'] = $price;
                        $item['amount'] = $price;
                    } elseif ($item['type'] === 'domain') {
                        $extension = DomainExtension::where('extension', $item['extension'])->first();
                        if (!$extension) continue;

                        $pricing = $extension->getRetailPricing($item['years'] ?? 1);
                        $item['unit_price'] = $pricing ? (float) $pricing->price : 0;
                        $item['amount'] = $item['unit_price'];
                    }

                    $subtotal += $item['amount'];
                    $cartItems[] = $item;
                }

                if (empty($cartItems)) {
                    throw new \Exception('No valid items in cart');
                }

                // Calculate totals
                $taxEnabled = Setting::getValue('tax_enabled') == 'true';
                $taxRate = (float) Setting::getValue('tax_rate', 0);
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
                    'order_number' => 'ORD-' . uniqid(),
                    'status' => 'pending',
                    'payment_status' => 'unpaid',
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                ]);

                // Create OrderItems, Services, and Domains
                foreach ($cartItems as $item) {
                    if ($item['type'] === 'product') {
                        $product = Product::find($item['product_id']);

                        // Create OrderItem
                        $orderItem = OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'description' => $product->name,
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
                            if (!empty($envValues)) {
                                $serviceMeta['env_values'] = $envValues;
                            }

                            // Store selected version for templated containers
                            $selectedVersionKey = "selected_version[{$item['key']}]";
                            $selectedVersion = $request->input($selectedVersionKey);
                            if (!empty($selectedVersion)) {
                                $serviceMeta['selected_version'] = $selectedVersion;
                            }

                            // Store selected database for provisioning
                            $techstack = session('selected_techstack', []);
                            if (!empty($techstack['database_id'])) {
                                $serviceMeta['database_id'] = (int) $techstack['database_id'];
                            }
                        }

                        if ($product->type === 'shared_hosting' && $product->provisioning_driver_key === 'directadmin') {
                            $daSetup = $this->setupDirectAdminService($product, $user);
                            $serviceMeta = array_merge($serviceMeta, $daSetup['meta']);
                            $nodeId = $daSetup['node_id'];
                        }

                        // Create Service
                        $service = Service::create([
                            'user_id' => $user->id,
                            'product_id' => $product->id,
                            'order_item_id' => $orderItem->id,
                            'name' => $product->name,
                            'status' => 'pending',
                            'billing_cycle' => $item['billing_cycle'],
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
                            'description' => $product->name,
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                        ]);
                    } elseif ($item['type'] === 'domain') {
                        $extension = DomainExtension::where('extension', $item['extension'])->first();

                        // Create Domain
                        $domain = Domain::create([
                            'user_id' => $user->id,
                            'name' => $item['domain'],
                            'extension' => $item['extension'],
                            'status' => 'pending',
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
                            ],
                        ]);

                        // Create Service for domain
                        $service = Service::create([
                            'user_id' => $user->id,
                            'product_id' => $domainProduct->id,
                            'order_item_id' => $orderItem->id,
                            'name' => "{$item['domain']}{$item['extension']}",
                            'status' => 'pending',
                            'billing_cycle' => 'annual',
                            'next_due_date' => now()->addDays($item['years'] * 365),
                            'service_meta' => [
                                'domain_id' => $domain->id,
                                'domain_name' => $item['domain'],
                                'extension' => $item['extension'],
                                'years' => $item['years'],
                            ],
                        ]);

                        // Create InvoiceItem
                        InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'service_id' => $service->id,
                            'product_id' => $domainProduct->id,
                            'description' => "{$item['domain']}{$item['extension']} ({$item['years']} year(s))",
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                        ]);
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
            return back()->with('error', 'Checkout failed: ' . $e->getMessage());
        }
    }
}
