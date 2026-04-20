<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoicePdfService
{
    /**
     * Generate PDF for invoice
     */
    public static function generate(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $invoice->load('user', 'items.product', 'items.service.product', 'payments', 'credits');

        $amountRemaining = max(0, $invoice->total - $invoice->getAmountPaid() - $invoice->getAppliedCredits());
        $amountPaid = $invoice->getAmountPaid();

        // Batch-fetch all settings in one query
        $settingKeys = [
            'billing_company', 'billing_address', 'billing_city', 'billing_country', 'billing_vat_number',
            'company_name', 'company_address', 'company_phone', 'company_email', 'company_website',
            'site_email', 'site_url', 'logo_url', 'footer_text',
            'tax_enabled', 'tax_rate', 'tax_name', 'tax_number',
            'mpesa_enabled', 'mpesa_shortcode', 'stripe_enabled', 'paypal_enabled', 'bank_transfer_enabled',
            'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch', 'bank_swift_code',
            'currency_symbol', 'primary_color',
        ];
        $settingsRaw = Setting::whereIn('key', $settingKeys)->pluck('value', 'key');
        $settings = $settingsRaw->toArray();

        // Convert logo URL to base64 data URI (DomPDF can't fetch remote URLs)
        $logoBase64 = self::getLogoBase64($settings['logo_url'] ?? '');

        // Company data
        $company = [
            'name' => $settings['billing_company'] ?? $settings['company_name'] ?? 'Talksasa Cloud',
            'address' => $settings['billing_address'] ?? '',
            'city' => $settings['billing_city'] ?? '',
            'country' => $settings['billing_country'] ?? '',
            'vat' => $settings['billing_vat_number'] ?? '',
            'email' => $settings['site_email'] ?? $settings['company_email'] ?? '',
            'website' => $settings['company_website'] ?? '',
            'logo' => $logoBase64,
            'footer' => $settings['footer_text'] ?? '',
            'color' => $settings['primary_color'] ?? '#2563eb',
        ];

        // Tax data
        $tax = [
            'enabled' => in_array($settings['tax_enabled'] ?? 'false', ['1', 'true', true], true),
            'rate' => $settings['tax_rate'] ?? '16',
            'name' => $settings['tax_name'] ?? 'VAT',
            'number' => $settings['tax_number'] ?? '',
        ];

        // Payment methods (only enabled)
        $paymentMethods = [
            'mpesa' => in_array($settings['mpesa_enabled'] ?? '', ['1', 'true', true], true),
            'stripe' => in_array($settings['stripe_enabled'] ?? '', ['1', 'true', true], true),
            'paypal' => in_array($settings['paypal_enabled'] ?? '', ['1', 'true', true], true),
            'bank' => in_array($settings['bank_transfer_enabled'] ?? '', ['1', 'true', true], true),
        ];

        // M-Pesa shortcode for paybill
        $mpesaShortcode = $settings['mpesa_shortcode'] ?? '';

        // Bank details
        $bank = [
            'name' => $settings['bank_name'] ?? '',
            'account' => $settings['bank_account_number'] ?? '',
            'holder' => $settings['bank_account_name'] ?? '',
            'branch' => $settings['bank_branch'] ?? '',
            'swift' => $settings['bank_swift_code'] ?? '',
        ];

        // Currency symbol
        $currencySymbol = $settings['currency_symbol'] ?? 'Ksh';

        // Site URL
        $siteUrl = $settings['site_url'] ?? config('app.url');

        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'user' => $invoice->user,
            'items' => $invoice->items,
            'amountRemaining' => $amountRemaining,
            'amountPaid' => $amountPaid,
            'company' => $company,
            'tax' => $tax,
            'paymentMethods' => $paymentMethods,
            'mpesaShortcode' => $mpesaShortcode,
            'bank' => $bank,
            'currencySymbol' => $currencySymbol,
            'siteUrl' => $siteUrl,
        ]);

        // Set options for better rendering
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isPhpEnabled', false);
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('margin_top', 15);
        $pdf->setOption('margin_bottom', 15);
        $pdf->setOption('margin_left', 15);
        $pdf->setOption('margin_right', 15);

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

    /**
     * Convert logo URL to base64 data URI for DomPDF embedding
     */
    private static function getLogoBase64(string $logoUrl): ?string
    {
        if (empty($logoUrl)) {
            return null;
        }

        try {
            $logoPath = ltrim($logoUrl, '/');

            // Convert /storage/... to storage/app/public/...
            if (str_starts_with($logoPath, 'storage/')) {
                $diskPath = str_replace('storage/', 'storage/app/public/', $logoPath);
            } else {
                $diskPath = $logoPath;
            }

            $fullPath = base_path($diskPath);

            if (!file_exists($fullPath)) {
                return null;
            }

            $mime = mime_content_type($fullPath);
            $data = file_get_contents($fullPath);

            return 'data:' . $mime . ';base64,' . base64_encode($data);
        } catch (\Exception $e) {
            \Log::warning('Failed to convert logo to base64', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
