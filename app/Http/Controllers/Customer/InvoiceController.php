<?php

namespace App\Http\Controllers\Customer;

use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $invoices = Invoice::where('user_id', auth()->id())
            ->latest()
            ->paginate(10);

        return view('customer.invoices.index', compact('invoices'));
    }

    public function show(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        $invoice->load('items.product', 'payments');
        return view('customer.invoices.show', compact('invoice'));
    }

    /**
     * Download invoice as PDF
     */
    public function download(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

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
