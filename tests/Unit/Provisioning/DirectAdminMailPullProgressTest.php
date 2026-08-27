<?php

namespace Tests\Unit\Provisioning;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminMailPullProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectAdminMailPullProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_percent_advances_per_mailbox_and_phase(): void
    {
        $progress = app(DirectAdminMailPullProgress::class);

        $this->assertSame(50, $progress->computePercent([
            'status' => 'running',
            'mailbox_index' => 1,
            'mailbox_total' => 2,
            'phase_fraction' => 1.0,
        ]));

        $this->assertSame(100, $progress->computePercent([
            'status' => 'completed',
            'mailbox_index' => 1,
            'mailbox_total' => 4,
            'phase_fraction' => 0.2,
        ]));
    }

    public function test_format_bytes_uses_readable_units(): void
    {
        $this->assertSame('512 B', DirectAdminMailPullProgress::formatBytes(512));
        $this->assertSame('1.5 KB', DirectAdminMailPullProgress::formatBytes(1536));
        $this->assertSame('2.0 MB', DirectAdminMailPullProgress::formatBytes(2097152));
    }

    public function test_queue_and_mailbox_progress_are_visible_on_snapshot(): void
    {
        $service = Service::factory()->create();
        $progress = app(DirectAdminMailPullProgress::class);

        $queued = $progress->queue($service);
        $this->assertSame('pending', $queued['status']);
        $this->assertTrue($progress->isActive($service));

        $progress->begin($service, 2);
        $progress->mailbox($service, 1, 2, 'info@winkairwaystraveladventure.co.ke', 1048576);
        $progress->phase($service, 'download', 'downloading 512 KB / 1.0 MB', 524288, 1048576);

        $snap = $progress->snapshot($service->fresh());
        $this->assertSame('running', $snap['status']);
        $this->assertSame('info@winkairwaystraveladventure.co.ke', $snap['current_email']);
        $this->assertSame(1, $snap['mailbox_index']);
        $this->assertSame(2, $snap['mailbox_total']);
        $this->assertGreaterThan(5, $snap['percent']);
        $this->assertLessThan(50, $snap['percent']);
        $this->assertStringContainsString('download', $snap['label']);
        $this->assertStringContainsString('info@winkairwaystraveladventure.co.ke', $snap['log']);
    }

    public function test_operator_view_allows_retry_when_mailcow_is_linked(): void
    {
        $email = Service::factory()->create([
            'product_id' => Product::factory()->emailHosting()->create()->id,
            'provisioning_driver_key' => 'mailcow',
        ]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'provisioning_driver_key' => 'container',
            'service_meta' => [
                'da_convert' => ['status' => 'completed'],
                'da_legacy' => ['email_service_id' => $email->id],
            ],
        ]);

        $view = app(DirectAdminMailPullProgress::class)->operatorView($service);

        $this->assertTrue($view['can_retry']);
        $this->assertFalse($view['is_active']);
        $this->assertSame('completed', $view['status']);
    }
}
