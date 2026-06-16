<?php

namespace App\Services;

use App\Models\Currency;
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
        $invoice->load('user', 'payments', 'credits')->loadItemsForDisplay();

        $amountRemaining = max(0, $invoice->total - $invoice->getAmountPaid() - $invoice->getAppliedCredits());
        $amountPaid = $invoice->getAmountPaid();

        $branding = app(ResellerBrandingResolver::class)->forInvoice($invoice);
        $reseller = app(ResellerBrandingResolver::class)->resellerForCustomer($invoice->user);
        $isWhiteLabel = (bool) ($branding['is_white_label'] ?? false);

        // Batch-fetch platform settings for tax, bank, payment methods
        $settingKeys = [
            'billing_company', 'billing_address', 'billing_city', 'billing_country', 'billing_vat_number',
            'company_name', 'company_address', 'company_phone', 'company_email', 'company_website',
            'site_email', 'site_url', 'logo_url', 'footer_text',
            'tax_enabled', 'tax_inclusive', 'tax_rate', 'tax_name', 'tax_number',
            'mpesa_enabled', 'mpesa_shortcode', 'stripe_enabled', 'paypal_enabled', 'bank_transfer_enabled', 'manual_enabled',
            'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch', 'bank_swift_code',
            'currency_symbol', 'primary_color',
        ];
        $settingsRaw = Setting::whereIn('key', $settingKeys)->pluck('value', 'key');
        $settings = $settingsRaw->toArray();

        $logoUrl = $isWhiteLabel ? ($branding['logo_url'] ?? '') : ($settings['logo_url'] ?? '');
        $logoBase64 = self::getLogoBase64($logoUrl);

        $mpesaShortcode = $settings['mpesa_shortcode'] ?? '';
        if ($reseller && ! empty($reseller->settings['mpesa']['business_shortcode'])) {
            $mpesaShortcode = $reseller->settings['mpesa']['business_shortcode'];
        }

        // Company data
        $company = [
            'name' => $isWhiteLabel
                ? ($branding['company_name'] ?? 'Talksasa Cloud')
                : ($settings['billing_company'] ?? $settings['company_name'] ?? 'Talksasa Cloud'),
            'address' => $isWhiteLabel ? '' : ($settings['billing_address'] ?? ''),
            'city' => $isWhiteLabel ? '' : ($settings['billing_city'] ?? ''),
            'country' => $isWhiteLabel ? '' : ($settings['billing_country'] ?? ''),
            'vat' => $isWhiteLabel ? '' : ($settings['billing_vat_number'] ?? ''),
            'email' => $isWhiteLabel
                ? ($branding['support_email'] ?? $invoice->user->email)
                : ($settings['site_email'] ?? $settings['company_email'] ?? ''),
            'website' => $isWhiteLabel ? ($branding['portal_url'] ?? '') : ($settings['company_website'] ?? ''),
            'logo' => $logoBase64,
            'footer' => $isWhiteLabel
                ? ($branding['footer_text'] ?? '')
                : ($settings['footer_text'] ?? ''),
            'color' => $isWhiteLabel
                ? ($branding['primary_color'] ?? '#7c3aed')
                : ($settings['primary_color'] ?? '#2563eb'),
        ];

        // Tax data
        $tax = [
            'enabled' => TaxService::isTruthy($settings['tax_enabled'] ?? null),
            'inclusive' => TaxService::isTruthy($settings['tax_inclusive'] ?? null),
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
            'manual' => in_array($settings['manual_enabled'] ?? '', ['1', 'true', true], true),
        ];

        // M-Pesa shortcode for paybill (may already be set from reseller config above)
        if (empty($mpesaShortcode)) {
            $mpesaShortcode = $settings['mpesa_shortcode'] ?? '';
        }

        // Bank details
        $bank = [
            'name' => $settings['bank_name'] ?? '',
            'account' => $settings['bank_account_number'] ?? '',
            'holder' => $settings['bank_account_name'] ?? '',
            'branch' => $settings['bank_branch'] ?? '',
            'swift' => $settings['bank_swift_code'] ?? '',
        ];

        $invoiceCurrency = Currency::where('code', $invoice->displayCurrency())->first();
        $currencySymbol = $invoiceCurrency?->symbol ?? $invoice->displayCurrency();

        // Site URL
        $siteUrl = $isWhiteLabel
            ? ($branding['portal_url'] ?? config('app.url'))
            : ($settings['site_url'] ?? config('app.url'));

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

        if (! is_dir("storage/{$path}")) {
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
            // Strip domain from full URLs (e.g., http://localhost:8000/storage/... → /storage/...)
            if (str_starts_with($logoUrl, 'http://') || str_starts_with($logoUrl, 'https://')) {
                $parsed = parse_url($logoUrl);
                $logoUrl = $parsed['path'] ?? '';
            }

            $logoPath = ltrim($logoUrl, '/');

            // Convert /storage/... to storage/app/public/...
            if (str_starts_with($logoPath, 'storage/')) {
                $diskPath = str_replace('storage/', 'storage/app/public/', $logoPath);
            } elseif (file_exists(public_path($logoPath))) {
                // Try public folder first
                $fullPath = public_path($logoPath);
                if (file_exists($fullPath)) {
                    $mime = mime_content_type($fullPath);
                    $data = file_get_contents($fullPath);

                    return 'data:'.$mime.';base64,'.base64_encode($data);
                }
            } else {
                $diskPath = $logoPath;
            }

            $fullPath = base_path($diskPath);

            if (! file_exists($fullPath)) {
                \Log::warning('Logo file not found', ['path' => $fullPath, 'original_url' => $logoUrl]);

                return null;
            }

            $mime = mime_content_type($fullPath);
            $data = file_get_contents($fullPath);

            return 'data:'.$mime.';base64,'.base64_encode($data);
        } catch (\Exception $e) {
            \Log::warning('Failed to convert logo to base64', ['error' => $e->getMessage(), 'url' => $logoUrl]);

            return null;
        }
    }
}
