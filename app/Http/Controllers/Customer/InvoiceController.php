<?php

namespace App\Http\Controllers\Customer;

use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;

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

    public function download(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        $invoice->load('user', 'items.product', 'payments');
        $settings = [
            'logo_url' => Setting::getValue('logo_url', ''),
            'company_name' => Setting::getValue('company_name', 'Talksasa Cloud'),
            'billing_address' => Setting::getValue('billing_address', ''),
            'billing_city' => Setting::getValue('billing_city', ''),
            'billing_country' => Setting::getValue('billing_country', ''),
            'billing_vat_number' => Setting::getValue('billing_vat_number', ''),
            'footer_text' => Setting::getValue('footer_text', ''),
        ];

        return Pdf::loadView('pdf.invoice', compact('invoice', 'settings'))
            ->download('invoice-' . $invoice->invoice_number . '.pdf');
    }
}
