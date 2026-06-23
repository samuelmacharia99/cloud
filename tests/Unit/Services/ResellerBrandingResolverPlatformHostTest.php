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

    public function test_platform_host_matches_site_url_even_when_app_url_differs(): void
    {
        config(['app.url' => 'https://talksasa.com']);

        $setting = Mockery::mock('alias:'.Setting::class);
        $setting->shouldReceive('getValue')
            ->with('site_url', 'https://talksasa.com')
            ->andReturn('https://servers.talksasa.com');

        $resolver = new ResellerBrandingResolver;

        $this->assertSame('https://servers.talksasa.com', $resolver->platformBaseUrl());
        $this->assertTrue($resolver->isPlatformHost('servers.talksasa.com'));
        $this->assertTrue($resolver->isPlatformHost('talksasa.com'));
        $this->assertFalse($resolver->isPlatformHost('billing.reseller.test'));
    }

    public function test_platform_base_url_normalizes_missing_scheme(): void
    {
        config(['app.url' => 'http://localhost']);

        $setting = Mockery::mock('alias:'.Setting::class);
        $setting->shouldReceive('getValue')
            ->with('site_url', 'http://localhost')
            ->andReturn('servers.talksasa.com');

        $resolver = new ResellerBrandingResolver;

        $this->assertSame('https://servers.talksasa.com', $resolver->platformBaseUrl());
    }
}
