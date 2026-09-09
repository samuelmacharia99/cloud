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
}
