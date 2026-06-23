<?php

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Models\User;
use App\Services\PlatformApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformApiTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_regenerate_stores_encrypted_token_for_later_copy(): void
    {
        Setting::setValue('public_website_api_enabled', '1');

        $admin = User::factory()->create([
            'is_admin' => true,
            'password' => Hash::make('secret-password'),
        ]);

        $service = app(PlatformApiTokenService::class);
        $plainText = $service->regenerate($admin);

        $this->assertTrue($service->hasActiveToken());
        $this->assertTrue($service->hasEncryptedPlainText());
        $this->assertSame($plainText, $service->revealPlainText());

        $metadata = $service->metadata();
        $this->assertTrue($metadata['copyable']);
        $this->assertSame(substr($plainText, -4), $metadata['hint']);
    }
}
