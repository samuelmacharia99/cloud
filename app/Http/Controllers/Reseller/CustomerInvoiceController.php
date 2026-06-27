<?php

namespace App\Http\Controllers\Reseller;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoicePdfService;
use App\Services\ResellerCustomerBillingService;
use App\Services\ResellerScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerInvoiceController extends Controller
{
    public function __construct(
        private ResellerScopeService $scope,
        private ResellerCustomerBillingService $billing,
    ) {}

    public function index(Request $request): View
    {
        $query = $this->scope->managedInvoicesQuery(auth()->user())
            ->with(['user', 'payments'])
            ->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('customer')) {
            $query->where('user_id', $request->customer);
        }

        $invoices = $query->paginate(20)->withQueryString();
        $customers = $this->scope->managedCustomersQuery(auth()->user())
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('reseller.customer-invoices.index', compact('invoices', 'customers'));
    }

    public function create(Request $request): View
    {
        $customers = $this->scope->managedCustomersQuery(auth()->user())
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $selectedCustomer = $request->filled('customer')
            ? $customers->firstWhere('id', (int) $request->customer)
            : null;

        $defaultLineItems = [
            ['description' => '', 'quantity' => 1, 'unit_price' => 0],
        ];

        return view('reseller.customer-invoices.create', compact('customers', 'selectedCustomer', 'defaultLineItems'));
    }

    public function store(Request $request): RedirectResponse
    {
        $reseller = auth()->user();
        $validated = $this->validateInvoicePayload($request);

        $customer = User::findOrFail($validated['customer_id']);
        $this->billing->ensureManagedCustomer($reseller, $customer);

        try {
            $invoice = $this->billing->createCustomerInvoice($reseller, $customer, $validated);

            return redirect()
                ->route('reseller.customer-invoices.show', $invoice)
                ->with('success', 'Invoice created successfully.');
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Failed to create invoice: '.$e->getMessage())->withInput();
        }
    }

    public function show(Invoice $invoice): View
    {
        $this->billing->ensureManagedInvoice(auth()->user(), $invoice);
        $invoice->load(['user', 'items.service', 'items.product', 'payments']);

        $amountRemaining = $invoice->getAmountRemaining();
        $canRecordPayment = ! in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true)
            && $amountRemaining > 0;
        $canEdit = ! in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true)
            && $invoice->payments()->where('status', 'completed')->doesntExist();

        return view('reseller.customer-invoices.show', compact(
            'invoice',
            'amountRemaining',
            'canRecordPayment',
            'canEdit',
        ));
    }

    public function edit(Invoice $invoice): View
    {
        $this->billing->ensureManagedInvoice(auth()->user(), $invoice);
        $invoice->load('items');

        if (in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true)) {
            abort(403);
        }

        $defaultLineItems = $invoice->items->map(function ($item) {
            return [
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
            ];
        })->values()->all();

        if ($defaultLineItems === []) {
            $defaultLineItems = [['description' => '', 'quantity' => 1, 'unit_price' => 0]];
        }

        return view('reseller.customer-invoices.edit', compact('invoice', 'defaultLineItems'));
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $reseller = auth()->user();
        $validated = $this->validateInvoicePayload($request, requireCustomer: false);

        try {
            $this->billing->updateCustomerInvoice($reseller, $invoice, $validated);

            return redirect()
                ->route('reseller.customer-invoices.show', $invoice)
                ->with('success', 'Invoice updated successfully.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Failed to update invoice.')->withInput();
        }
    }

    public function cancel(Invoice $invoice): RedirectResponse
    {
        try {
            $this->billing->cancelInvoice(auth()->user(), $invoice);

            return back()->with('success', 'Invoice cancelled.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function markPaid(Invoice $invoice): RedirectResponse
    {
        try {
            $this->billing->markAsPaid(auth()->user(), $invoice);

            return back()->with('success', 'Invoice marked as paid.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function resend(Invoice $invoice): RedirectResponse
    {
        try {
            $this->billing->resendInvoice(auth()->user(), $invoice);

            return back()->with('success', 'Invoice email sent to customer.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function addPayment(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->billing->ensureManagedInvoice(auth()->user(), $invoice);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:'.max(0.01, $invoice->getAmountRemaining()),
            'payment_method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'transaction_reference' => 'nullable|string|max:255',
            'paid_at' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $this->billing->recordPayment(auth()->user(), $invoice, $validated);

            return back()->with('success', 'Payment recorded successfully.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Failed to record payment: '.$e->getMessage());
        }
    }

    public function download(Invoice $invoice)
    {
        $this->billing->ensureManagedInvoice(auth()->user(), $invoice);

        return InvoicePdfService::download($invoice);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateInvoicePayload(Request $request, bool $requireCustomer = true): array
    {
        $rules = [
            'status' => 'required|in:draft,unpaid',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ];

        if ($requireCustomer) {
            $rules['customer_id'] = 'required|exists:users,id';
        }

        return $request->validate($rules);
    }
}
