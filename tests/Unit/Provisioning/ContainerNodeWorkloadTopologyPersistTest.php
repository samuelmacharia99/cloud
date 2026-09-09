<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerNodeWorkloadTopologyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerNodeWorkloadTopologyPersistTest extends TestCase
{
    use RefreshDatabase;

    public function test_persist_clears_a_stale_mobile_frontend_pin_on_api_only_stacks(): void
    {
        $template = ContainerTemplate::factory()->create([
            'slug' => 'nodejs',
            'default_port' => 3000,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'service_meta' => [
                'framework' => 'express',
                'frontend' => 'vite-spa',
                'node_backend_root' => 'apps/api',
                'node_frontend_root' => 'apps/mobile',
            ],
        ]);

        (new ContainerNodeWorkloadTopologyService)->persist($service, [
            'schema' => 1,
            'topology' => 'single',
            'selection_source' => 'auto_api',
            'frontend_type' => 'none',
            'backend' => ['root' => 'apps/api'],
            'skipped_mobile' => ['apps/mobile'],
        ]);

        $service->refresh();
        $this->assertSame('apps/api', $service->service_meta['node_backend_root']);
        $this->assertArrayNotHasKey('node_frontend_root', $service->service_meta ?? []);
        $this->assertSame('auto_api', $service->service_meta['node_workloads']['selection_source']);
    }

    public function test_persist_keeps_an_expo_web_frontend_pin_on_a_split_stack(): void
    {
        $template = ContainerTemplate::factory()->create([
            'slug' => 'nodejs',
            'default_port' => 3000,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'service_meta' => [
                'framework' => 'express',
                'frontend' => 'vite-spa',
                'node_backend_root' => 'apps/api',
            ],
        ]);

        (new ContainerNodeWorkloadTopologyService)->persist($service, [
            'schema' => 1,
            'topology' => 'split_web_api',
            'selection_source' => 'auto',
            'frontend_type' => 'expo-web',
            'backend' => ['root' => 'apps/api'],
            'frontend' => ['root' => 'apps/mobile'],
            'skipped_mobile' => [],
        ]);

        $service->refresh();
        $this->assertSame('apps/api', $service->service_meta['node_backend_root']);
        $this->assertSame('apps/mobile', $service->service_meta['node_frontend_root']);
        $this->assertSame('split_web_api', $service->service_meta['node_workloads']['topology']);
        $this->assertSame('expo-web', $service->service_meta['node_workloads']['frontend_type']);
    }

    public function test_persist_restores_a_project_web_pin_over_a_detected_api_root(): void
    {
        $template = ContainerTemplate::factory()->create([
            'slug' => 'nodejs',
            'default_port' => 3000,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'service_meta' => [
                'project_role' => 'frontend',
                'frontend' => 'none',
                'node_backend_root' => 'apps/api',
                'node_project_root' => 'apps/mobile',
            ],
        ]);

        (new ContainerNodeWorkloadTopologyService)->persist($service, [
            'schema' => 1,
            'topology' => 'single',
            'selection_source' => 'project_role',
            'frontend_type' => 'none',
            'backend' => ['root' => 'apps/mobile'],
        ]);

        $service->refresh();
        $this->assertSame('apps/mobile', $service->service_meta['node_backend_root']);
        $this->assertSame('apps/mobile', $service->service_meta['node_project_root']);
        $this->assertArrayNotHasKey('node_frontend_root', $service->service_meta);
    }
}
