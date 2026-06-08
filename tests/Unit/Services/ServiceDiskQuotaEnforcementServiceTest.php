<?php

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Services\ServiceDiskQuotaEnforcementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceDiskQuotaEnforcementServiceTest extends TestCase
{
    use RefreshDatabase;
    private ServiceDiskQuotaEnforcementService $enforcement;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::create(['key' => 'disk_overquota_threshold_percent', 'value' => '100']);

        $this->enforcement = app(ServiceDiskQuotaEnforcementService::class);
    }

    public function test_detects_over_quota_at_threshold(): void
    {
        $this->assertTrue($this->enforcement->isOverQuota(1024.0, 1024.0));
        $this->assertFalse($this->enforcement->isOverQuota(1023.0, 1024.0));
    }

    public function test_unlimited_quota_never_triggers_over_quota(): void
    {
        $this->assertFalse($this->enforcement->isOverQuota(50000.0, null));
        $this->assertFalse($this->enforcement->isOverQuota(50000.0, 0.0));
    }
}
