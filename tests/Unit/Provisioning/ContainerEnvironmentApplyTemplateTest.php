<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerEnvironmentApplyTemplateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function apply_environment_resolves_template_from_service_meta_when_product_has_none(): void
    {
        $template = ContainerTemplate::factory()->create([
            'slug' => 'nodejs',
            'docker_image' => 'node:20-alpine',
            'default_port' => 3000,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => null,
        ]);
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'service_meta' => [
                'provision_template_slug' => 'nodejs',
                'container_template_id' => $template->id,
            ],
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'env_values' => ['API_URL' => 'https://example.test'],
        ]);

        $this->assertNull($service->fresh()->product?->containerTemplate);

        try {
            app(ContainerDeploymentService::class)->applyEnvironmentVariables(
                $service->fresh(['product.containerTemplate', 'containerDeployment.node']),
                $deployment->fresh('node')
            );
        } catch (\DomainException $e) {
            $this->assertNotSame(
                'Container template is missing.',
                $e->getMessage(),
                'Env apply must use the service-meta template, not only product.containerTemplate'
            );
        } catch (\Throwable $e) {
            $this->assertNotSame('Container template is missing.', $e->getMessage());
        }
    }
}
