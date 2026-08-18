<?php

namespace Tests\Unit\Console\Commands;

use App\Models\Product;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncServiceLiveStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_checks_pending_container_services_without_ssh(): void
    {
        $product = Product::factory()->containerHosting()->create();
        Service::factory()->create([
            'product_id' => $product->id,
            'status' => 'pending',
            'provisioning_driver_key' => 'container',
        ]);

        $this->artisan('cron:sync-service-live-status', ['--limit' => 10, '--max-runtime' => 30])
            ->assertSuccessful()
            ->expectsOutputToContain('Checked 1 services');
    }
}
