<?php

namespace Tests\Unit\Services;

use App\Services\InvoicePdfService;
use Tests\TestCase;

class InvoicePdfServiceTest extends TestCase
{
    public function test_hex_to_rgb_parses_brand_and_short_colors(): void
    {
        $this->assertSame([37, 99, 235], InvoicePdfService::hexToRgb('#2563eb'));
        $this->assertSame([255, 255, 255], InvoicePdfService::hexToRgb('#fff'));
        $this->assertSame([255, 255, 255], InvoicePdfService::hexToRgb('not-a-color'));
    }

    public function test_alpha_png_logo_is_flattened_to_a_small_jpeg(): void
    {
        $path = $this->writeAlphaPng(1200, 600);

        $jpeg = InvoicePdfService::preparePdfLogoJpeg($path, '#2563eb');

        $this->assertNotNull($jpeg);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $jpeg);
        $this->assertLessThan(filesize($path), strlen($jpeg));

        $image = imagecreatefromstring($jpeg);
        $this->assertNotFalse($image);
        $this->assertLessThanOrEqual(360, imagesx($image));
        $this->assertLessThanOrEqual(160, imagesy($image));
        imagedestroy($image);

        @unlink($path);
    }

    public function test_logo_data_uri_is_jpeg_not_raw_png(): void
    {
        $path = $this->writeAlphaPng(400, 200);
        $url = '/storage/testing/'.basename($path);

        $resolved = InvoicePdfService::resolveLocalLogoPath($path);
        $this->assertSame($path, $resolved);

        $uri = InvoicePdfService::logoDataUriForPdf($path, '#7c3aed');
        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $uri);
        $this->assertStringNotContainsString('image/png', $uri);

        @unlink($path);
    }

    public function test_missing_logo_does_not_block_pdf_branding(): void
    {
        $this->assertNull(InvoicePdfService::logoDataUriForPdf('/storage/missing-brand-logo.png'));
        $this->assertNull(InvoicePdfService::resolveLocalLogoPath('https://servers.talksasa.com/storage/missing.png'));
    }

    private function writeAlphaPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        $blue = imagecolorallocatealpha($image, 37, 99, 235, 40);
        imagefilledrectangle($image, 10, 10, $width - 10, $height - 10, $blue);

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
