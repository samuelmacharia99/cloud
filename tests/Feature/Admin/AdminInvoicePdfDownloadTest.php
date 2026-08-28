<?php

namespace Tests\Feature\Admin;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoicePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInvoicePdfDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_download_invoice_pdf_when_logo_is_a_large_transparent_png(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'type' => 'reseller_subscription',
            'status' => 'unpaid',
            'subtotal' => 268.10,
            'tax' => 0,
            'total' => 268.10,
            'notes' => 'Reseller Package Upgrade: Turbo → Turbo-Kickstart (prorated, 7 of 30 days remaining)',
        ]);

        $path = $this->writeAlphaPng(1600, 800);
        $publicUrl = '/storage/testing/'.basename($path);
        Setting::setValue('logo_url', $publicUrl);
        Setting::setValue('primary_color', '#2563eb');
        Setting::setValue('company_name', 'Talksasa Cloud');

        $this->assertStringStartsWith(
            'data:image/jpeg;base64,',
            (string) InvoicePdfService::logoDataUriForPdf($publicUrl, '#2563eb')
        );

        $this->actingAs($admin)
            ->get(route('admin.invoices.download', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        @unlink($path);
    }

    public function test_admin_can_download_invoice_pdf_without_a_logo(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = Invoice::factory()->create([
            'status' => 'unpaid',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.invoices.download', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function writeAlphaPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        $blue = imagecolorallocatealpha($image, 37, 99, 235, 50);
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), $width - 40, $height - 40, $blue);

        $dir = storage_path('app/public/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/invoice-logo-'.uniqid('', true).'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
