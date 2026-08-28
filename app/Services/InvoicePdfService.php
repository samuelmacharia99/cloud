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

        $amountRemaining = $invoice->getAmountRemaining();
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

        $logoUrl = (string) ($branding['logo_url'] ?? $settings['logo_url'] ?? '');
        $headerColor = (string) ($branding['primary_color'] ?? $settings['primary_color'] ?? '#2563eb');
        $logoBase64 = self::logoDataUriForPdf($logoUrl, $headerColor);

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
            'items' => $invoice->itemsForDisplay(),
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
     * Embed a PDF-safe logo. DomPDF walks every pixel of PNG alpha masks, so a
     * large transparent brand PNG exceeds PHP's 30s web timeout during download.
     */
    public static function logoDataUriForPdf(string $logoUrl, string $backgroundHex = '#ffffff'): ?string
    {
        if ($logoUrl === '') {
            return null;
        }

        try {
            $fullPath = self::resolveLocalLogoPath($logoUrl);
            if ($fullPath === null) {
                \Log::warning('Logo file not found', ['original_url' => $logoUrl]);

                return null;
            }

            $prepared = self::preparePdfLogoJpeg($fullPath, $backgroundHex);
            if ($prepared === null) {
                return null;
            }

            return 'data:image/jpeg;base64,'.base64_encode($prepared);
        } catch (\Throwable $e) {
            \Log::warning('Failed to convert logo for invoice PDF', [
                'error' => $e->getMessage(),
                'url' => $logoUrl,
            ]);

            return null;
        }
    }

    public static function resolveLocalLogoPath(string $logoUrl): ?string
    {
        $logoUrl = trim($logoUrl);
        if ($logoUrl === '') {
            return null;
        }

        if (is_file($logoUrl)) {
            return $logoUrl;
        }

        if (str_starts_with($logoUrl, 'http://') || str_starts_with($logoUrl, 'https://')) {
            $parsed = parse_url($logoUrl);
            $logoUrl = $parsed['path'] ?? '';
        }

        $logoPath = ltrim($logoUrl, '/');
        if ($logoPath === '') {
            return null;
        }

        $candidates = [];

        if (str_starts_with($logoPath, 'storage/')) {
            $relative = substr($logoPath, strlen('storage/'));
            $candidates[] = storage_path('app/public/'.$relative);
            $candidates[] = public_path($logoPath);
            $candidates[] = base_path(str_replace('storage/', 'storage/app/public/', $logoPath));
        } else {
            $candidates[] = public_path($logoPath);
            $candidates[] = storage_path('app/public/'.$logoPath);
            $candidates[] = base_path($logoPath);
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Resize and flatten onto the invoice header colour so DomPDF never sees an alpha channel.
     */
    public static function preparePdfLogoJpeg(string $fullPath, string $backgroundHex = '#ffffff'): ?string
    {
        if (! is_file($fullPath) || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $size = filesize($fullPath);
        if ($size === false || $size > 20 * 1024 * 1024) {
            \Log::warning('Invoice PDF logo skipped: file too large', [
                'path' => $fullPath,
                'bytes' => $size,
            ]);

            return null;
        }

        $mtime = (string) filemtime($fullPath);
        $cacheKey = sha1($fullPath.'|'.$mtime.'|'.$backgroundHex.'|360x160');
        $cacheDir = storage_path('app/pdf-logos');
        $cachePath = $cacheDir.'/'.$cacheKey.'.jpg';
        if (is_file($cachePath)) {
            $cached = file_get_contents($cachePath);

            return $cached !== false ? $cached : null;
        }

        $raw = file_get_contents($fullPath);
        if ($raw === false || $raw === '') {
            return null;
        }

        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            return null;
        }

        $width = imagesx($src);
        $height = imagesy($src);
        if ($width < 1 || $height < 1) {
            imagedestroy($src);

            return null;
        }

        $maxWidth = 360;
        $maxHeight = 160;
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        if ($dst === false) {
            imagedestroy($src);

            return null;
        }

        [$red, $green, $blue] = self::hexToRgb($backgroundHex);
        imagefill($dst, 0, 0, imagecolorallocate($dst, $red, $green, $blue));
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($src);

        ob_start();
        imagejpeg($dst, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($dst);

        if (! is_string($jpeg) || $jpeg === '') {
            return null;
        }

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cachePath, $jpeg);

        return $jpeg;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3 && ctype_xdigit($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return [255, 255, 255];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
