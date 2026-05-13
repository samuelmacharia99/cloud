<?php

namespace App\Http\Controllers\Reseller;

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
            ->paginate(15);

        return view('reseller.invoices.index', compact('invoices'));
    }

    public function show(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        $invoice->load('items.product', 'payments');
        return view('reseller.invoices.show', compact('invoice'));
    }

    /**
     * Download invoice as PDF
     */
    public function download(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        return InvoicePdfService::download($invoice);
    }
}
