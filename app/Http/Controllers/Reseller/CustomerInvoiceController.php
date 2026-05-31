<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoicePdfService;
use App\Services\ResellerScopeService;
use Illuminate\Http\Request;

class CustomerInvoiceController extends Controller
{
    public function __construct(
        private ResellerScopeService $scope,
    ) {}

    public function index(Request $request)
    {
        $query = $this->scope->managedInvoicesQuery(auth()->user())
            ->with('user')
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

    public function show(Invoice $invoice)
    {
        $this->ensureManagedInvoice($invoice);
        $invoice->load(['user', 'items.service', 'items.product', 'payments']);

        return view('reseller.customer-invoices.show', compact('invoice'));
    }

    public function download(Invoice $invoice)
    {
        $this->ensureManagedInvoice($invoice);

        return InvoicePdfService::download($invoice);
    }

    private function ensureManagedInvoice(Invoice $invoice): void
    {
        $customer = $invoice->user;
        if (! $customer instanceof User || ! $this->scope->ownsCustomer(auth()->user(), $customer)) {
            abort(404);
        }
    }
}
