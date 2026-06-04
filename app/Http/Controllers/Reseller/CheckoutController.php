<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Reseller\Concerns\ResellerDomainAccess;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ResellerDomainOrder;
use App\Models\Setting;
use App\Models\User;
use App\Services\DomainPushService;
use App\Services\DomainRenewalService;
use App\Services\NotificationService;
use App\Services\ResellerCustomerOrderService;
use App\Services\ResellerInvoicePaymentService;
use App\Services\ResellerScopeService;
use App\Services\ResellerWalletService;
use App\Support\ResellerCartContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    use ResellerDomainAccess;

    public function __construct(
        protected ResellerWalletService $walletService,
        protected ResellerInvoicePaymentService $invoicePaymentService,
        protected DomainRenewalService $renewalService,
        protected ResellerCustomerOrderService $customerOrders,
        protected ResellerScopeService $scope,
    ) {}

    public function show(): View|RedirectResponse
    {
        $cart = session(CartController::CART_KEY, []);

        if (empty($cart)) {
            return redirect()->route('reseller.cart.index')
                ->with('warning', 'Your cart is empty');
        }

        $items = [];
        $subtotal = 0;

        foreach ($cart as $key => $item) {
            if (in_array($item['type'] ?? 'domain', ['domain', 'domain_renewal'], true)) {
                $total = CartController::cartItemTotal($item);
                $subtotal += $total;
                $items[$key] = array_merge($item, ['total' => $total]);
            }
        }

        $taxEnabled = Setting::getValue('tax_enabled') === 'true';
        $taxRate = $taxEnabled ? (float) Setting::getValue('tax_rate', 0) : 0;
        $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
        $total = $subtotal + $tax;

        $user = auth()->user();
        $checkoutCustomer = $this->resolveCheckoutCustomer();
        $isCustomerCheckout = $checkoutCustomer !== null;

        $wallet = $this->walletService->getOrCreate($user);
        $walletApplicable = $isCustomerCheckout ? 0 : min((float) $wallet->balance, $total);

        return view('reseller.checkout.index', compact(
            'items',
            'subtotal',
            'tax',
            'taxEnabled',
            'taxRate',
            'total',
            'user',
            'wallet',
            'walletApplicable',
            'checkoutCustomer',
            'isCustomerCheckout',
        ));
    }

    public function process(Request $request)
    {
        $request->validate([
            'agree' => 'required|accepted',
        ]);
        $cart = session(CartController::CART_KEY, []);

        if (empty($cart)) {
            return redirect()->route('reseller.cart.index')
                ->with('error', 'Your cart is empty');
        }

        $reseller = auth()->user();

        $checkoutCustomer = $this->resolveCheckoutCustomer();
        if ($checkoutCustomer) {
            return $this->processCustomerCheckout($cart, $reseller, $checkoutCustomer);
        }
        $subtotal = 0;
        $invoiceItems = [];
        $domainOrders = [];
        $hasRegistration = false;
        $hasRenewal = false;

        try {
            \DB::beginTransaction();

            foreach ($cart as $item) {
                if (($item['type'] ?? 'domain') === 'domain_renewal') {
                    $domain = Domain::findOrFail($item['domain_id']);
                    $this->assertResellerCanManageDomain($domain);

                    $wholesaleAmount = $this->renewalService->wholesaleRenewalAmount($domain, (int) $item['years']);
                    $subtotal += $wholesaleAmount;
                    $hasRenewal = true;

                    $renewalOrder = $this->renewalService->initiateResellerRenewal($domain, $reseller, (int) $item['years']);

                    $invoiceItems[] = [
                        'description' => 'Renew '.$domain->name.$domain->extension.' ('.$item['years'].' year'.($item['years'] > 1 ? 's' : '').')',
                        'quantity' => 1,
                        'unit_price' => $wholesaleAmount,
                        'domain_id' => $domain->id,
                        'custom_options' => ['renewal_order_id' => $renewalOrder->id],
                    ];

                    continue;
                }

                if ($item['type'] === 'domain') {
                    $hasRegistration = true;
                    $extension = DomainExtension::where('extension', $item['extension'])->first();

                    if (! $extension) {
                        throw new \Exception("Extension {$item['extension']} not found");
                    }

                    $wholesalePrice = $extension->pricing()
                        ->where('tier', 'wholesale')
                        ->where('period_years', $item['years'])
                        ->first();

                    if (! $wholesalePrice) {
                        throw new \Exception("No wholesale pricing for {$item['extension']} ({$item['years']} years)");
                    }

                    $wholesaleAmount = $wholesalePrice->price * $item['years'];
                    $subtotal += $wholesaleAmount;

                    // Create domain
                    $domain = Domain::create([
                        'user_id' => $reseller->id,
                        'name' => $item['domain'],
                        'extension' => $item['extension'],
                        'status' => 'pending',
                        'type' => 'registration',
                        'auto_renew' => false,
                    ]);

                    // Create reseller domain order
                    $order = ResellerDomainOrder::create([
                        'reseller_id' => $reseller->id,
                        'customer_id' => $reseller->id,
                        'domain_id' => $domain->id,
                        'domain_name' => $item['domain'],
                        'extension' => $item['extension'],
                        'years' => $item['years'],
                        'wholesale_amount' => $wholesaleAmount,
                        'retail_amount' => 0,
                        'status' => 'queued',
                    ]);

                    $domainOrders[] = $order->id;

                    // Create invoice item with domain order reference
                    $invoiceItems[] = [
                        'description' => $item['domain'].$item['extension'].' ('.$item['years'].' year'.($item['years'] > 1 ? 's' : '').')',
                        'quantity' => 1,
                        'unit_price' => $wholesaleAmount,
                        'custom_options' => ['domain_order_id' => $order->id],
                    ];
                }
            }

            $taxEnabled = Setting::getValue('tax_enabled') === 'true';
            $taxRate = $taxEnabled ? (float) Setting::getValue('tax_rate', 0) : 0;
            $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
            $total = $subtotal + $tax;

            $invoiceNotes = match (true) {
                $hasRegistration && $hasRenewal => 'Domain registration and renewal order',
                $hasRenewal => 'Domain renewal order',
                default => 'Domain registration order',
            };

            // Create invoice
            $invoice = Invoice::create([
                'user_id' => $reseller->id,
                'invoice_number' => $this->generateInvoiceNumber(),
                'status' => 'unpaid',
                'due_date' => now()->addDays((int) Setting::getValue('invoice_due_days', 30)),
                'subtotal' => $subtotal,
                'tax' => $taxEnabled ? $tax : 0,
                'total' => $total,
                'notes' => $invoiceNotes,
            ]);

            // Create invoice items
            foreach ($invoiceItems as $itemData) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => null,
                    'product_type' => 'Domain',
                    'domain_id' => $itemData['domain_id'] ?? null,
                    'description' => $itemData['description'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'amount' => $itemData['quantity'] * $itemData['unit_price'],
                    'custom_options' => $itemData['custom_options'],
                ]);

                if (isset($itemData['custom_options']['renewal_order_id'])) {
                    $renewalOrder = DomainRenewalOrder::find($itemData['custom_options']['renewal_order_id']);
                    if ($renewalOrder) {
                        $this->renewalService->linkRenewalToInvoice($renewalOrder, $invoice);
                    }
                }
            }

            \DB::commit();

            // Link domain orders to this invoice for self-purchase push flow
            ResellerDomainOrder::whereIn('id', $domainOrders)->update([
                'customer_invoice_id' => $invoice->id,
            ]);

            session()->forget(CartController::CART_KEY);

            if ($request->boolean('apply_wallet')) {
                $this->invoicePaymentService->applyWallet($invoice, $reseller, true);
                $invoice->refresh();

                if ($this->invoicePaymentService->amountDue($invoice) <= 0) {
                    $this->invoicePaymentService->completeInvoiceIfFullyPaid($invoice);
                    app(NotificationService::class)->notifyPaymentReceived($invoice);
                    app(DomainPushService::class)->handlePaidResellerInvoice($invoice->fresh(['items']));
                    $this->processDomainRenewals($invoice);

                    return redirect()->route('reseller.invoices.show', $invoice)
                        ->with('success', 'Order placed and paid using your wallet balance.');
                }

                return redirect()->route('reseller.invoices.show', $invoice)
                    ->with('success', 'Order placed. Wallet applied — pay the remaining KES '
                        .number_format($this->invoicePaymentService->amountDue($invoice), 2).' to complete.');
            }

            return redirect()->route('reseller.invoices.show', $invoice)
                ->with('success', 'Order created successfully. Please proceed to payment.');
        } catch (\Exception $e) {
            \DB::rollBack();

            return redirect()->route('reseller.checkout.show')
                ->withInput()
                ->with('error', 'Failed to create order: '.$e->getMessage());
        }
    }

    private function generateInvoiceNumber(): string
    {
        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $date = now()->format('Ymd');
        $count = Invoice::whereDate('created_at', now())->count() + 1;

        return "{$prefix}-{$date}-".str_pad($count, 5, '0', STR_PAD_LEFT);
    }

    private function processDomainRenewals(Invoice $invoice): void
    {
        $renewalOrders = DomainRenewalOrder::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'invoiced')
            ->get();

        foreach ($renewalOrders as $order) {
            app(DomainRenewalService::class)->pushRenewalToAdmin($order);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $cart
     */
    private function processCustomerCheckout(array $cart, $reseller, $checkoutCustomer): RedirectResponse
    {
        if (collect($cart)->contains(fn ($item) => ($item['type'] ?? 'domain') === 'domain_renewal')) {
            return redirect()->route('reseller.cart.index')
                ->with('error', 'Renewals cannot be billed to customers via cart. Use your account cart for renewals.');
        }

        try {
            $invoice = $this->customerOrders->checkoutDomainCartForCustomer(
                $reseller,
                $checkoutCustomer,
                array_values($cart),
            );

            session()->forget(CartController::CART_KEY);
            ResellerCartContext::setSelf();

            return redirect()->route('reseller.customer-invoices.show', $invoice)
                ->with('success', 'Customer invoice created at your retail prices. Collect payment from your customer to complete registration.');
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('reseller.checkout.show')
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('reseller.checkout.show')
                ->with('error', 'Failed to create customer invoice.');
        }
    }

    private function resolveCheckoutCustomer(): ?User
    {
        if (! ResellerCartContext::isCustomerMode()) {
            return null;
        }

        $customerId = ResellerCartContext::customerId();
        if (! $customerId) {
            return null;
        }

        $customer = User::find($customerId);
        $reseller = auth()->user();

        if (! $customer || ! $this->scope->ownsCustomer($reseller, $customer)) {
            ResellerCartContext::setSelf();

            return null;
        }

        return $customer;
    }
}
