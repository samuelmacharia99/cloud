<?php

namespace Tests\Feature\Customer;

use App\Jobs\ProvisionContainerServiceJob;
use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Customer\ProjectNodeWebSplitService;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\ResellerEnforcementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProjectNodeWebSplitServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_a_named_web_container_on_the_same_project(): void
    {
        Bus::fake();
        $this->mock(InvoiceProvisioningService::class, function ($mock) {
            $mock->shouldReceive('shouldAutoProvisionService')->andReturn(true);
        });
        $this->mock(ResellerEnforcementService::class, function ($mock) {
            $mock->shouldReceive('assertCanProvision');
        });
        [$api, $project] = $this->makeNodeApiService();

        $split = app(ProjectNodeWebSplitService::class)->apply($api);
        app(ProjectNodeWebSplitService::class)->provisionNewFrontendIfNeeded($split);

        $this->assertNotNull($split);
        $this->assertTrue($split['created']);

        $api->refresh();
        $web = $split['frontend']->refresh();

        $this->assertNotNull($web);
        $this->assertIsArray($web->service_meta);
        $this->assertIsArray($api->service_meta);

        $this->assertSame($project->id, $web->project_id);
        $this->assertSame('Web', $web->service_meta['project_role_label']);
        $this->assertSame('frontend', $web->service_meta['project_role']);
        $this->assertSame('apps/mobile', $web->service_meta['node_application_root']);
        $this->assertSame('apps/mobile', $web->service_meta['node_backend_root']);
        $this->assertSame('apps/api', $web->service_meta['sibling_application_root']);
        $this->assertSame('none', $web->service_meta['frontend']);
        $this->assertSame('https://github.com/ndinda7688/sameplan', $web->service_meta['source_repo_url']);
        $this->assertSame(0.0, (float) $web->custom_price);
        $this->assertNull($web->invoice_id);
        $this->assertTrue((bool) ($web->service_meta['included_on_project_plan'] ?? false));

        $this->assertSame('API', $api->service_meta['project_role_label']);
        $this->assertSame('backend', $api->service_meta['project_role']);
        $this->assertSame('none', $api->service_meta['frontend']);
        $this->assertSame('apps/api', $api->service_meta['node_application_root']);
        $this->assertSame('apps/mobile', $api->service_meta['sibling_application_root']);
        $this->assertArrayNotHasKey('node_frontend_root', $api->service_meta);
        $this->assertSame($web->id, $api->service_meta['frontend_service_id']);
        $this->assertSame($project->name.'-api', $api->name);

        Bus::assertDispatched(
            ProvisionContainerServiceJob::class,
            fn (ProvisionContainerServiceJob $job) => $job->serviceId === $web->id
        );
        $this->assertSame(2, Service::query()->where('project_id', $project->id)->count());
    }

    public function test_a_later_redeploy_reuses_the_existing_web_service(): void
    {
        Bus::fake();
        $this->mock(InvoiceProvisioningService::class, function ($mock) {
            $mock->shouldReceive('shouldAutoProvisionService')->andReturn(true);
        });
        $this->mock(ResellerEnforcementService::class, function ($mock) {
            $mock->shouldReceive('assertCanProvision');
        });
        [$api] = $this->makeNodeApiService();

        $first = app(ProjectNodeWebSplitService::class)->apply($api);
        $webMeta = $first['frontend']->fresh()->service_meta;
        $webMeta['node_backend_root'] = 'apps/api';
        $first['frontend']->update(['service_meta' => $webMeta]);
        $meta = $api->fresh()->service_meta;
        $meta['frontend'] = 'nextjs';
        $meta['node_frontend_root'] = 'apps/mobile';
        $api->fresh()->update(['service_meta' => $meta]);

        $again = app(ProjectNodeWebSplitService::class)->apply($api->fresh());

        $this->assertFalse($again['created']);
        $this->assertSame($first['frontend']->id, $again['frontend']->id);
        $this->assertSame('none', $api->fresh()->service_meta['frontend']);
        $this->assertSame('apps/mobile', $again['frontend']->fresh()->service_meta['node_application_root']);
        $this->assertSame('apps/mobile', $again['frontend']->fresh()->service_meta['node_backend_root']);
        $this->assertSame('apps/mobile', $again['frontend']->fresh()->service_meta['node_project_root']);
        $this->assertSame('apps/api', $again['frontend']->fresh()->service_meta['sibling_application_root']);
    }

    public function test_it_does_not_split_when_frontend_is_none(): void
    {
        [$api] = $this->makeNodeApiService([
            'frontend' => 'none',
        ]);

        $this->assertNull(app(ProjectNodeWebSplitService::class)->apply($api->fresh()));
    }

    /**
     * @param  array<string, mixed>  $metaOverrides
     * @return array{0: Service, 1: CustomerProject}
     */
    private function makeNodeApiService(array $metaOverrides = []): array
    {
        $customer = User::factory()->customer()->create();
        $template = ContainerTemplate::factory()->create([
            'slug' => 'nodejs',
            'name' => 'Node.js',
            'is_active' => true,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'name' => 'TIER 3',
            'container_template_id' => $template->id,
        ]);
        $project = CustomerProject::factory()->create([
            'user_id' => $customer->id,
            'name' => 'carslynk',
        ]);
        $api = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'name' => 'TIER 3',
            'status' => 'failed',
            'billing_cycle' => 'monthly',
            'custom_price' => 999,
            'provisioning_driver_key' => 'container',
            'service_meta' => array_merge([
                'backend' => 'nodejs',
                'frontend' => 'nextjs',
                'framework' => 'nextjs',
                'language_slug' => 'nodejs',
                'container_template_id' => $template->id,
                'node_backend_root' => 'apps/api',
                'node_frontend_root' => 'apps/mobile',
                'project_recipe' => 'plan_pool',
                'project_role' => 'primary',
                'project_billing_anchor' => true,
                'source_repo_url' => 'https://github.com/ndinda7688/sameplan',
                'source_repo_branch' => 'main',
                'selected_version' => '22-alpine',
            ], $metaOverrides),
        ]);
        $project->update(['billing_service_id' => $api->id]);

        return [$api->fresh(), $project->fresh()];
    }
}
