<?php

namespace Tests\Feature\Admin;

use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The compute pool is only useful if an operator can set one, and only safe if
 * an omitted field means unmetered rather than null.
 */
class AdminResellerPackageComputeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_set_a_compute_pool_on_a_package(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.reseller-packages.store'), $this->payload([
                'cpu_pool_cores' => 8.5,
                'memory_pool_mb' => 16384,
            ]))
            ->assertRedirect();

        $package = ResellerPackage::where('name', 'Compute plan')->firstOrFail();

        $this->assertSame('8.50', (string) $package->cpu_pool_cores);
        $this->assertSame(16384, $package->memory_pool_mb);
    }

    public function test_a_package_saved_without_a_compute_pool_is_unmetered(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.reseller-packages.store'), $this->payload())
            ->assertRedirect();

        $package = ResellerPackage::where('name', 'Compute plan')->firstOrFail();

        $this->assertSame('0.00', (string) $package->cpu_pool_cores);
        $this->assertSame(0, $package->memory_pool_mb);
    }

    public function test_an_absurd_pool_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.reseller-packages.store'), $this->payload(['cpu_pool_cores' => 100000]))
            ->assertSessionHasErrors('cpu_pool_cores');

        $this->assertDatabaseMissing('reseller_packages', ['name' => 'Compute plan']);
    }

    public function test_an_admin_can_change_the_pool_on_an_existing_package(): void
    {
        $package = ResellerPackage::create($this->payload([
            'storage_space' => 100,
            'cpu_pool_cores' => 2,
            'memory_pool_mb' => 4096,
        ]));

        $this->actingAs($this->admin())
            ->put(route('admin.reseller-packages.update', $package), $this->payload([
                'cpu_pool_cores' => 12,
                'memory_pool_mb' => 32768,
            ]))
            ->assertRedirect();

        $package->refresh();

        $this->assertSame('12.00', (string) $package->cpu_pool_cores);
        $this->assertSame(32768, $package->memory_pool_mb);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Compute plan',
            'description' => 'Application hosting reseller plan',
            'billing_cycle' => 'monthly',
            'disk_pool_gb' => 100,
            'max_services' => 25,
            'max_users' => 25,
            'price' => 5000,
            'active' => true,
        ], $overrides);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
