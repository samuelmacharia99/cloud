<?php

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Services\ResellerBrandingResolver;
use Mockery;
use Tests\TestCase;

class ResellerBrandingResolverPlatformHostTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_platform_host_matches_site_url_and_app_url(): void
    {
        config(['app.url' => 'https://servers.talksasa.com']);

        $setting = Mockery::mock('alias:'.Setting::class);
        $setting->shouldReceive('getValue')
            ->with('site_url', 'https://servers.talksasa.com')
            ->andReturn('https://talksasa.com');
        $setting->shouldReceive('getValue')
            ->with('site_url', '')
            ->andReturn('https://talksasa.com');

        $resolver = new ResellerBrandingResolver;

        $this->assertSame('https://talksasa.com', $resolver->platformBaseUrl());
        $this->assertSame('https://servers.talksasa.com', $resolver->publicApiBaseUrl());
        $this->assertTrue($resolver->isPlatformHost('servers.talksasa.com'));
        $this->assertTrue($resolver->isPlatformHost('talksasa.com'));
        $this->assertFalse($resolver->isPlatformHost('billing.reseller.test'));
    }

    public function test_public_api_base_url_falls_back_to_site_url_when_app_url_missing(): void
    {
        config(['app.url' => '']);

        $setting = Mockery::mock('alias:'.Setting::class);
        $setting->shouldReceive('getValue')
            ->with('site_url', '')
            ->andReturn('servers.talksasa.com');

        $resolver = new ResellerBrandingResolver;

        $this->assertSame('https://servers.talksasa.com', $resolver->publicApiBaseUrl());
    }
}
