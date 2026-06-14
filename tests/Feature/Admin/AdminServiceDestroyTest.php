<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminServiceDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_destroy_deprovisions_container_before_database_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);

        $this->mock(ProvisioningService::class, function ($mock) use ($service) {
            $mock->shouldReceive('terminate')
                ->once()
                ->with(\Mockery::on(fn ($record) => $record->id === $service->id));
        });

        $this->actingAs($admin)
            ->delete(route('admin.services.destroy', $service))
            ->assertRedirect(route('admin.services.index'));

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    public function test_admin_destroy_blocks_delete_when_active_service_deprovision_fails(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);

        $this->mock(ProvisioningService::class, function ($mock) {
            $mock->shouldReceive('terminate')
                ->once()
                ->andThrow(new \RuntimeException('SSH unavailable'));
        });

        $this->actingAs($admin)
            ->from(route('admin.services.index'))
            ->delete(route('admin.services.destroy', $service))
            ->assertRedirect(route('admin.services.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('services', ['id' => $service->id]);
    }
}
