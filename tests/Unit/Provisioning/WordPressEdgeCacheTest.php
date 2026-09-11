<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Provisioning\NginxProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Anonymous WordPress pages are served from the node's nginx.
 *
 * The rule that matters is the one that keeps a cache from becoming a leak: a
 * response that could belong to one visitor rather than all of them must never
 * be stored. Every bypass below is load-bearing.
 */
class WordPressEdgeCacheTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_shared_file_declares_the_cache_and_the_login_limit(): void
    {
        $shared = app(NginxProxyService::class)->sharedNginxConfig();

        $this->assertStringContainsString('proxy_cache_path /var/cache/nginx/talksasa', $shared);
        $this->assertStringContainsString('keys_zone=talksasa_wp:', $shared);
        $this->assertStringContainsString('limit_req_zone $binary_remote_addr zone=talksasa_wp_login:', $shared);
    }

    #[Test]
    public function a_wordpress_vhost_caches_and_rate_limits(): void
    {
        $config = $this->config(wordpress: true, sharedAvailable: true);

        $this->assertStringContainsString('proxy_cache talksasa_wp;', $config);
        $this->assertStringContainsString('proxy_cache_valid 200 60s;', $config);
        $this->assertStringContainsString('add_header X-Talksasa-Cache', $config);
        $this->assertStringContainsString('location = /wp-login.php {', $config);
        $this->assertStringContainsString('location = /xmlrpc.php {', $config);
        $this->assertStringContainsString('limit_req zone=talksasa_wp_login', $config);
    }

    #[Test]
    public function every_bypass_that_keeps_one_visitors_page_private_is_present(): void
    {
        $config = $this->config(wordpress: true, sharedAvailable: true);

        foreach ([
            'wordpress_logged_in',
            'wordpress_sec',
            'wp-postpass',
            'comment_author',
            'woocommerce_items_in_cart',
            'woocommerce_cart_hash',
            'wp_woocommerce_session',
        ] as $cookie) {
            $this->assertStringContainsString($cookie, $config, $cookie.' must bypass the cache');
        }

        $this->assertStringContainsString('if ($request_method !~ ^(GET|HEAD)$)', $config);
        $this->assertStringContainsString('wp-admin', $config);
        $this->assertStringContainsString('wp-json', $config);
        $this->assertStringContainsString('$arg_preview', $config);
        $this->assertStringContainsString('proxy_no_cache $talksasa_skip_cache;', $config);
    }

    #[Test]
    public function a_node_without_the_shared_file_gets_a_vhost_that_never_mentions_it(): void
    {
        // Naming a zone the node does not have fails nginx -t and takes the
        // site down, so an un-upgraded node keeps exactly what it serves today.
        $config = $this->config(wordpress: true, sharedAvailable: false);

        $this->assertStringNotContainsString('proxy_cache', $config);
        $this->assertStringNotContainsString('limit_req', $config);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:8080;', $config);
    }

    #[Test]
    public function a_stack_that_is_not_wordpress_is_left_exactly_as_it_was(): void
    {
        $config = $this->config(wordpress: false, sharedAvailable: true);

        $this->assertStringNotContainsString('proxy_cache', $config);
        $this->assertStringNotContainsString('wp-login.php', $config);
    }

    #[Test]
    public function the_service_switch_turns_it_off(): void
    {
        $config = $this->config(wordpress: true, sharedAvailable: true, meta: ['wordpress_page_cache' => false]);

        $this->assertStringNotContainsString('proxy_cache', $config);
        $this->assertStringNotContainsString('limit_req', $config);
    }

    #[Test]
    public function the_platform_wide_switch_turns_it_off_everywhere(): void
    {
        Setting::updateOrCreate(['key' => 'wordpress_page_cache_enabled'], ['value' => 'false']);
        cache()->flush();

        $this->assertStringNotContainsString('proxy_cache', $this->config(wordpress: true, sharedAvailable: true));
    }

    #[Test]
    public function a_suspended_site_serves_the_notice_rather_than_a_cached_page(): void
    {
        $config = $this->config(wordpress: true, sharedAvailable: true, suspended: true);

        $this->assertStringNotContainsString('proxy_cache', $config);
        $this->assertStringContainsString('return 503;', $config);
    }

    #[Test]
    public function the_revision_marker_moves_so_existing_sites_are_rewritten_once(): void
    {
        $this->assertSame('v8', NginxProxyService::VHOST_REVISION);
        $this->assertStringContainsString('# talksasa-vhost v8', $this->config(wordpress: true, sharedAvailable: true));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function config(
        bool $wordpress,
        bool $sharedAvailable,
        array $meta = [],
        bool $suspended = false,
    ): string {
        $template = new ContainerTemplate(['slug' => $wordpress ? 'wordpress' : 'nodejs']);
        $product = new Product(['type' => 'container_hosting']);
        $product->setRelation('containerTemplate', $template);

        $service = new Service(['service_meta' => $meta, 'status' => 'active']);
        $service->setRelation('product', $product);

        $deployment = new ContainerDeployment;
        $deployment->container_name = 'user-1-service-1-'.($wordpress ? 'wordpress' : 'nodejs');
        $deployment->assigned_port = 8080;
        $deployment->setRelation('service', $service);
        $service->setRelation('containerDeployment', $deployment);

        $domain = new ContainerDomain(['domain' => 'example.test']);
        $domain->setRelation('deployment', $deployment);

        return app(NginxProxyService::class)->generateConfig($domain, false, $suspended, $sharedAvailable);
    }
}
