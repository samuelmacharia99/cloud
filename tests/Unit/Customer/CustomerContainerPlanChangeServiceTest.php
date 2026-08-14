<?php

namespace Tests\Unit\Customer;

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Customer\CustomerContainerPlanChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerContainerPlanChangeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_only_same_template_family(): void
    {
        $user = User::factory()->create();
        $template = ContainerTemplate::factory()->create(['slug' => 'laravel-plan-a-'.uniqid()]);
        $otherTemplate = ContainerTemplate::factory()->create(['slug' => 'nodejs-plan-b-'.uniqid()]);

        $current = Product::factory()->create([
            'type' => 'container_hosting',
            'is_active' => true,
            'monthly_price' => 1000,
            'yearly_price' => 10000,
            'container_template_id' => $template->id,
            'resource_limits' => ['cpu' => 1, 'memory' => 512, 'disk' => 5],
        ]);
        $upgrade = Product::factory()->create([
            'type' => 'container_hosting',
            'is_active' => true,
            'monthly_price' => 2000,
            'yearly_price' => 20000,
            'container_template_id' => $template->id,
            'resource_limits' => ['cpu' => 2, 'memory' => 2048, 'disk' => 20],
        ]);
        Product::factory()->create([
            'type' => 'container_hosting',
            'is_active' => true,
            'monthly_price' => 2500,
            'yearly_price' => 25000,
            'container_template_id' => $otherTemplate->id,
            'resource_limits' => ['cpu' => 4, 'memory' => 4096, 'disk' => 40],
        ]);

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $current->id,
        ]);

        $options = app(CustomerContainerPlanChangeService::class)->optionsForService($service);

        $this->assertCount(1, $options);
        $this->assertSame($upgrade->id, $options->first()['product']->id);
        $this->assertSame('upgrade', $options->first()['change_type']);
        $this->assertSame(2000.0, $options->first()['display_price']);
    }
}
