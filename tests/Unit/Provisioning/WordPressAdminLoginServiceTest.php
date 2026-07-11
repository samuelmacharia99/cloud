<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\WordPressAdminLoginService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WordPressAdminLoginServiceTest extends TestCase
{
    #[Test]
    public function it_detects_wordpress_container_services(): void
    {
        $template = new ContainerTemplate(['slug' => 'wordpress']);
        $product = new Product(['type' => 'container_hosting']);
        $product->setRelation('containerTemplate', $template);
        $service = new Service;
        $service->setRelation('product', $product);

        $login = new WordPressAdminLoginService;

        $this->assertTrue($login->isWordPressContainer($service));
    }

    #[Test]
    public function it_rejects_non_wordpress_services(): void
    {
        $template = new ContainerTemplate(['slug' => 'laravel']);
        $product = new Product(['type' => 'container_hosting']);
        $product->setRelation('containerTemplate', $template);
        $service = new Service;
        $service->setRelation('product', $product);

        $this->assertFalse((new WordPressAdminLoginService)->isWordPressContainer($service));
    }

    #[Test]
    public function mu_plugin_contains_sso_hook(): void
    {
        $contents = (new WordPressAdminLoginService)->muPluginContents();

        $this->assertStringContainsString('talksasa_admin_sso', $contents);
        $this->assertStringContainsString('wp_set_auth_cookie', $contents);
        $this->assertStringContainsString('.talksasa-admin-sso.json', $contents);
        $this->assertStringContainsString('admin_url()', $contents);
    }
}
