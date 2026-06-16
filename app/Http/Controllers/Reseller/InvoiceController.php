<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use App\Services\ResellerInvoicePaymentService;
use App\Services\ResellerWalletService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(
        protected ResellerWalletService $walletService,
        protected ResellerInvoicePaymentService $invoicePaymentService,
    ) {}

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

        $invoice->loadItemsForDisplay()->load('payments');
        $wallet = $this->walletService->getOrCreate(auth()->user());
        $amountDue = $this->invoicePaymentService->amountDue($invoice);

        return view('reseller.invoices.show', compact('invoice', 'wallet', 'amountDue'));
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
