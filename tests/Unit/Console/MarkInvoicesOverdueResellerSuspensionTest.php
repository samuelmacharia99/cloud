<?php

namespace Tests\Unit\Console;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkInvoicesOverdueResellerSuspensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('grace_period_days', '5');
        Setting::setValue('reseller_suspend_on_overdue', 'true');
        Setting::setValue('suspend_on_overdue', 'false');
    }

    public function test_marks_reseller_subscription_overdue_and_suspends_past_grace(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $reseller = User::factory()->reseller()->create([
            'package_expires_at' => now()->addMonth(),
        ]);

        Invoice::factory()->create([
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'status' => 'unpaid',
            'due_date' => Carbon::parse('2026-06-01'),
        ]);

        $this->artisan('cron:mark-invoices-overdue')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Overdue, $reseller->fresh()->invoices()->first()?->status);
        $this->assertTrue($reseller->fresh()->isResellerSuspended());

        Carbon::setTestNow();
    }

    public function test_does_not_suspend_reseller_subscription_before_grace_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-03'));

        $reseller = User::factory()->reseller()->create([
            'package_expires_at' => now()->addMonth(),
        ]);

        Invoice::factory()->create([
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'status' => 'unpaid',
            'due_date' => Carbon::parse('2026-06-01'),
        ]);

        $this->artisan('cron:mark-invoices-overdue')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Overdue, $reseller->fresh()->invoices()->first()?->status);
        $this->assertFalse($reseller->fresh()->isResellerSuspended());

        Carbon::setTestNow();
    }
}
