<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoicePdfService
{
    /**
     * Generate PDF for invoice
     */
    public static function generate(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $invoice->load('user', 'items.product');

        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'user' => $invoice->user,
            'items' => $invoice->items,
            'company' => [
                'name' => \App\Models\Setting::getValue('company_name', 'Talksasa Cloud'),
                'address' => \App\Models\Setting::getValue('company_address', ''),
                'phone' => \App\Models\Setting::getValue('company_phone', ''),
                'email' => \App\Models\Setting::getValue('company_email', ''),
                'website' => \App\Models\Setting::getValue('company_website', ''),
                'logo' => \App\Models\Setting::getValue('logo_url', ''),
            ],
        ]);

        // Set options for better rendering
        $pdf->setPaper('a4');
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isPhpEnabled', true);
        $pdf->setOption('isRemoteEnabled', true);

        return $pdf;
    }

    /**
     * Download invoice as PDF
     */
    public static function download(Invoice $invoice)
    {
        $pdf = self::generate($invoice);
        $filename = "Invoice-{$invoice->invoice_number}.pdf";

        return $pdf->download($filename);
    }

    /**
     * Stream invoice as PDF (view in browser)
     */
    public static function stream(Invoice $invoice)
    {
        $pdf = self::generate($invoice);

        return $pdf->stream();
    }

    /**
     * Save invoice PDF to disk
     */
    public static function save(Invoice $invoice, string $path = 'invoices'): string
    {
        $pdf = self::generate($invoice);
        $filename = "invoice-{$invoice->id}-{$invoice->invoice_number}.pdf";
        $fullPath = "storage/{$path}/{$filename}";

        // Ensure directory exists
        if (!is_dir("storage/{$path}")) {
            mkdir("storage/{$path}", 0755, true);
        }

        $pdf->save($fullPath);

        return $fullPath;
    }

    /**
     * Get PDF as string (for attachment)
     */
    public static function getStream(Invoice $invoice): string
    {
        $pdf = self::generate($invoice);

        return $pdf->output();
    }
}
