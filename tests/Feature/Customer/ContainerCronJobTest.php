<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerCronJob;
use App\Models\ContainerDeployment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerCronJobTest extends TestCase
{
    use RefreshDatabase;

    private function containerServiceFor(User $customer): Service
    {
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);

        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'status' => 'running',
        ]);

        return $service;
    }

    public function test_customer_can_create_cron_job_from_container_page(): void
    {
        $customer = User::factory()->create();
        $service = $this->containerServiceFor($customer);

        $this->actingAs($customer)
            ->post(route('customer.services.container.cron-jobs.store', $service), [
                'name' => 'Scheduler',
                'schedule' => '*/10 * * * *',
                'command' => 'php artisan schedule:run',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('container_cron_jobs', [
            'service_id' => $service->id,
            'name' => 'Scheduler',
            'command' => 'php artisan schedule:run',
        ]);
    }

    public function test_customer_cannot_create_cron_job_for_another_users_service(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = $this->containerServiceFor($owner);

        $this->actingAs($other)
            ->post(route('customer.services.container.cron-jobs.store', $service), [
                'name' => 'Scheduler',
                'schedule' => '* * * * *',
                'command' => 'php artisan schedule:run',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('container_cron_jobs', 0);
    }

    public function test_customer_can_delete_own_cron_job(): void
    {
        $customer = User::factory()->create();
        $service = $this->containerServiceFor($customer);
        $job = ContainerCronJob::create([
            'service_id' => $service->id,
            'name' => 'Old job',
            'schedule' => '0 * * * *',
            'command' => 'php artisan inspire',
            'enabled' => true,
        ]);

        $this->actingAs($customer)
            ->delete(route('customer.services.container.cron-jobs.delete', [$service, $job]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('container_cron_jobs', ['id' => $job->id]);
    }
}
