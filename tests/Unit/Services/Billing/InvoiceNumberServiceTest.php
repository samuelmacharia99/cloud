<?php

namespace Tests\Unit\Services\Billing;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_yearly_uses_highest_existing_sequence_not_row_count(): void
    {
        $user = User::factory()->create();
        $year = now()->format('Y');

        Invoice::factory()->create([
            'user_id' => $user->id,
            'invoice_number' => "INV-{$year}-00152",
        ]);

        Invoice::factory()->create([
            'user_id' => $user->id,
            'invoice_number' => "INV-{$year}-00001",
        ]);

        $next = app(InvoiceNumberService::class)->nextYearly('INV', (int) $year);

        $this->assertSame("INV-{$year}-00153", $next);
    }

    public function test_next_yearly_starts_at_one_when_no_invoices_exist(): void
    {
        $year = now()->format('Y');

        $next = app(InvoiceNumberService::class)->nextYearly('INV', (int) $year);

        $this->assertSame("INV-{$year}-00001", $next);
    }

    public function test_next_daily_uses_date_prefix_sequence(): void
    {
        $user = User::factory()->create();
        $datePart = now()->format('Ymd');

        Invoice::factory()->create([
            'user_id' => $user->id,
            'invoice_number' => "INV-{$datePart}-00007",
        ]);

        $next = app(InvoiceNumberService::class)->nextDaily('INV', now());

        $this->assertSame("INV-{$datePart}-00008", $next);
    }
}
