<?php

namespace Tests\Unit\Console\Commands;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProvisionPendingContainersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_includes_failed_status_services(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'paid',
        ]);

        $pending = Service::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'status' => 'pending',
            'provisioning_driver_key' => 'container',
        ]);

        $failed = Service::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'status' => 'failed',
            'provisioning_driver_key' => 'container',
        ]);

        Service::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'status' => 'active',
            'provisioning_driver_key' => 'container',
        ]);

        $provisionedIds = [];

        $mock = Mockery::mock(ProvisioningService::class);
        $mock->shouldReceive('provision')
            ->twice()
            ->andReturnUsing(function (Service $service) use (&$provisionedIds) {
                $provisionedIds[] = $service->id;
            });
        $this->app->instance(ProvisioningService::class, $mock);

        $this->artisan('cron:provision-pending-containers')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$pending->id, $failed->id], $provisionedIds);
    }
}
