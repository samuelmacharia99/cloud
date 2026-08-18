<?php

namespace Tests\Unit\Mail;

use App\Mail\DomainAutoRenewUnpaidMail;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainAutoRenewUnpaidMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_tells_customer_to_top_up_credits_for_the_domain(): void
    {
        $user = User::factory()->customer()->create();
        $domain = Domain::create([
            'user_id' => $user->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'expires_at' => now()->addDays(20),
            'auto_renew' => true,
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'total' => 1200,
            'subtotal' => 1200,
            'tax' => 0,
            'invoice_number' => 'INV-AR-TEST',
        ]);
        $invoice->setRelation('user', $user);

        $html = (new DomainAutoRenewUnpaidMail(
            $invoice,
            $domain,
            1200,
            'account credits',
            route('customer.credits.index'),
            route('customer.invoices.show', $invoice),
        ))->render();

        $this->assertStringContainsString('example.com', $html);
        $this->assertStringContainsString('1,200.00', $html);
        $this->assertStringContainsString('account credits', $html);
        $this->assertStringContainsString(route('customer.credits.index'), $html);
    }
}
