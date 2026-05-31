<?php

namespace Tests\Unit\Services;

use App\Models\Domain;
use App\Models\Service;
use App\Models\User;
use App\Services\InvoiceGenerationScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceGenerationScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceGenerationScheduleService $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schedule = app(InvoiceGenerationScheduleService::class);
    }

    public function test_monthly_reseller_customer_service_is_due_ten_days_before_next_due_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-21'));

        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'next_due_date' => Carbon::parse('2026-03-31'),
        ]);

        $this->assertTrue($this->schedule->isResellerManagedService($service));
        $this->assertTrue($this->schedule->isServiceDueForRenewalInvoice($service));
        $this->assertSame(
            '2026-03-21',
            $this->schedule->serviceInvoiceGenerateOnOrBefore($service)->toDateString()
        );

        Carbon::setTestNow();
    }

    public function test_annual_reseller_customer_service_is_due_thirty_days_before_next_due_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'billing_cycle' => 'annual',
            'status' => 'active',
            'next_due_date' => Carbon::parse('2026-03-31'),
        ]);

        $this->assertTrue($this->schedule->isServiceDueForRenewalInvoice($service));
        $this->assertSame(
            '2026-03-01',
            $this->schedule->serviceInvoiceGenerateOnOrBefore($service)->toDateString()
        );

        Carbon::setTestNow();
    }

    public function test_reseller_customer_domain_is_due_thirty_days_before_expiry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'expires_at' => Carbon::parse('2026-03-31'),
        ]);

        $this->assertTrue($this->schedule->isResellerManagedDomain($domain));
        $this->assertTrue($this->schedule->isDomainDueForRenewalInvoice($domain));
        $this->assertSame(
            '2026-03-01',
            $this->schedule->domainInvoiceGenerateOnOrBefore($domain)->toDateString()
        );
        $this->assertSame(
            '2026-03-01',
            $this->schedule->domainNextInvoiceDate($domain)->toDateString()
        );

        Carbon::setTestNow();
    }

    public function test_monthly_service_not_due_outside_ten_day_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $service = Service::factory()->create([
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'next_due_date' => Carbon::parse('2026-03-31'),
        ]);

        $this->assertFalse($this->schedule->isServiceDueForRenewalInvoice($service));

        Carbon::setTestNow();
    }
}
