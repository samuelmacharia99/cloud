<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\DomainExtension;
use App\Models\ResellerProduct;
use App\Models\User;
use App\Services\ResellerCustomerBillingService;
use App\Services\ResellerCustomerOrderService;
use App\Services\ResellerScopeService;
use App\Support\ResellerCartContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerOrderController extends Controller
{
    public function __construct(
        private ResellerScopeService $scope,
        private ResellerCustomerBillingService $billing,
        private ResellerCustomerOrderService $orders,
    ) {}

    public function createHosting(Request $request): View
    {
        $reseller = auth()->user();
        $customers = $this->scope->managedCustomersQuery($reseller)->orderBy('name')->get(['id', 'name', 'email']);
        $products = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_active', true)
            ->with('adminProduct')
            ->orderBy('name')
            ->get();

        $selectedCustomer = $request->filled('customer')
            ? $customers->firstWhere('id', (int) $request->customer)
            : null;

        return view('reseller.customer-orders.create-hosting', compact('customers', 'products', 'selectedCustomer'));
    }

    public function storeHosting(Request $request): RedirectResponse
    {
        $reseller = auth()->user();

        $validated = $request->validate([
            'customer_id' => 'required|exists:users,id',
            'reseller_product_id' => 'required|exists:reseller_products,id',
            'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
            'order_type' => 'required|in:provision,invoice_only',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        $customer = User::findOrFail($validated['customer_id']);
        $this->billing->ensureManagedCustomer($reseller, $customer);

        $product = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('id', $validated['reseller_product_id'])
            ->firstOrFail();

        try {
            if ($validated['order_type'] === 'invoice_only') {
                $unitPrice = $product->priceForBillingCycle($validated['billing_cycle']);
                $invoice = $this->billing->createCustomerInvoice($reseller, $customer, [
                    'status' => 'unpaid',
                    'due_date' => $validated['due_date'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'tax_rate' => 0,
                    'items' => [[
                        'description' => "{$product->name} ({$validated['billing_cycle']})",
                        'quantity' => 1,
                        'unit_price' => $unitPrice,
                        'product_id' => $product->product_id,
                    ]],
                ]);

                return redirect()->route('reseller.customer-invoices.show', $invoice)
                    ->with('success', 'Invoice created for your customer.');
            }

            $result = $this->orders->orderHostingFromCatalog(
                $reseller,
                $customer,
                $product,
                $validated['billing_cycle'],
                [
                    'due_date' => $validated['due_date'] ?? null,
                    'invoice_notes' => $validated['notes'] ?? null,
                ],
            );

            return redirect()->route('reseller.customer-invoices.show', $result['invoice'])
                ->with('success', 'Hosting order created. Service will provision when the customer invoice is paid.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Failed to create order.')->withInput();
        }
    }

    public function createDomain(Request $request): View
    {
        $reseller = auth()->user();
        $customers = $this->scope->managedCustomersQuery($reseller)->orderBy('name')->get(['id', 'name', 'email']);
        $extensions = DomainExtension::with([
            'pricing' => fn ($q) => $q->where('tier', 'wholesale'),
            'resellerPricing' => fn ($q) => $q->where('reseller_id', $reseller->id)->where('enabled', true),
        ])->where('enabled', true)->orderBy('extension')->get();

        $selectedCustomer = $request->filled('customer')
            ? $customers->firstWhere('id', (int) $request->customer)
            : null;

        if ($selectedCustomer) {
            ResellerCartContext::setCustomer($selectedCustomer->id);
            ResellerCartContext::setCustomerName($selectedCustomer->name);
        }

        return view('reseller.customer-orders.create-domain', compact('customers', 'extensions', 'selectedCustomer'));
    }

    public function storeDomain(Request $request): RedirectResponse
    {
        $reseller = auth()->user();

        $validated = $request->validate([
            'customer_id' => 'required|exists:users,id',
            'domain' => 'required|string|max:63|regex:/^[a-z0-9-]+$/i',
            'extension_id' => 'required|exists:domain_extensions,id',
            'years' => 'required|integer|min:1|max:10',
        ]);

        $customer = User::findOrFail($validated['customer_id']);
        $extension = DomainExtension::findOrFail($validated['extension_id']);

        try {
            $result = $this->orders->orderDomainForCustomer(
                $reseller,
                $customer,
                $validated['domain'],
                $extension,
                (int) $validated['years'],
            );

            return redirect()->route('reseller.customer-invoices.show', $result['invoice'])
                ->with('success', 'Domain order created for your customer at your retail price.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Failed to register domain for customer.')->withInput();
        }
    }

    /** @deprecated Use createHosting */
    public function create(Request $request): View
    {
        return $this->createHosting($request);
    }

    /** @deprecated Use storeHosting */
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['order_type' => 'invoice_only']);

        return $this->storeHosting($request);
    }
}
