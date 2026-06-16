<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        $query = Invoice::where('user_id', auth()->id())->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('invoice_number', 'like', "%{$search}%");
        }

        $invoices = $query->paginate(10)->withQueryString();

        return view('customer.invoices.index', compact('invoices'));
    }

    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        $invoice->loadItemsForDisplay()->load('credits');

        return view('customer.invoices.show', [
            'invoice' => $invoice,
            'appliedCredits' => $invoice->getAppliedCredits(),
            'amountRemaining' => $invoice->getAmountRemaining(),
        ]);
    }

    /**
     * Download invoice as PDF
     */
    public function download(Invoice $invoice)
    {
        $this->authorize('download', $invoice);

        return InvoicePdfService::download($invoice);
    }

    /**
     * View invoice as PDF in browser
     */
    public function preview(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        return InvoicePdfService::stream($invoice);
    }
}
