<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\LaravelWelcomePageService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LaravelWelcomePageServiceTest extends TestCase
{
    #[Test]
    public function it_detects_default_laravel_cloud_welcome_content(): void
    {
        $service = new LaravelWelcomePageService;

        $this->assertTrue($service->contentIsDefaultLaravelWelcome(
            '<a href="https://cloud.laravel.com/">Deploy now</a>'
        ));
    }

    #[Test]
    public function it_skips_custom_welcome_pages(): void
    {
        $service = new LaravelWelcomePageService;

        $this->assertFalse($service->contentIsDefaultLaravelWelcome(
            '<h1>My Company Homepage</h1><p>Powered by Talksasa Cloud</p>'
        ));
    }
}
