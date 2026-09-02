<?php

namespace Tests\Unit\Services\Checkout;

use App\Enums\InvoiceStatus;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Checkout\CheckoutDomainRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CheckoutDomainRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_pending_domain_when_none_exists(): void
    {
        $user = User::factory()->customer()->create();

        $domain = $this->service()->createOrReuse($user, 'SimpleProject', '.co.ke', [
            'status' => 'pending',
            'nameserver_1' => 'albert.ns.cloudflare.com',
        ]);

        $this->assertSame('simpleproject', $domain->name);
        $this->assertSame('.co.ke', $domain->extension);
        $this->assertSame($user->id, $domain->user_id);
        $this->assertSame('pending', $domain->status);
        $this->assertSame(1, Domain::query()->where('name', 'simpleproject')->count());
    }

    public function test_reuses_same_user_pending_domain_without_an_open_invoice(): void
    {
        $user = User::factory()->customer()->create();
        $existing = Domain::create([
            'user_id' => $user->id,
            'name' => 'simpleproject',
            'extension' => '.co.ke',
            'status' => 'pending',
        ]);

        $domain = $this->service()->createOrReuse($user, 'simpleproject', '.co.ke', [
            'nameserver_1' => 'albert.ns.cloudflare.com',
            'registrant_contact' => ['first_name' => 'Kate'],
        ]);

        $this->assertSame($existing->id, $domain->id);
        $this->assertSame('albert.ns.cloudflare.com', $domain->nameserver_1);
        $this->assertSame('Kate', $domain->registrant_contact['first_name']);
        $this->assertSame(1, Domain::count());
    }

    public function test_reuses_pending_domain_when_the_only_open_invoice_is_the_current_checkout(): void
    {
        $user = User::factory()->customer()->create();
        $existing = Domain::create([
            'user_id' => $user->id,
            'name' => 'simpleproject',
            'extension' => '.co.ke',
            'status' => 'pending',
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => InvoiceStatus::Unpaid,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'domain_id' => $existing->id,
            'description' => 'simpleproject.co.ke (1 year(s))',
            'quantity' => 1,
            'unit_price' => 1500,
            'amount' => 1500,
        ]);

        $domain = $this->service()->createOrReuse(
            $user,
            'simpleproject',
            '.co.ke',
            ['nameserver_1' => 'aliza.ns.cloudflare.com'],
            $invoice->id,
        );

        $this->assertSame($existing->id, $domain->id);
        $this->assertSame(1, Domain::count());
    }

    public function test_rejects_same_user_pending_domain_with_a_separate_unpaid_invoice(): void
    {
        $user = User::factory()->customer()->create();
        $existing = Domain::create([
            'user_id' => $user->id,
            'name' => 'simpleproject',
            'extension' => '.co.ke',
            'status' => 'pending',
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => InvoiceStatus::Unpaid,
            'invoice_number' => 'INV-KEEP-00001',
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'domain_id' => $existing->id,
            'description' => 'simpleproject.co.ke (1 year(s))',
            'quantity' => 1,
            'unit_price' => 1500,
            'amount' => 1500,
        ]);

        try {
            $this->service()->createOrReuse($user, 'simpleproject', '.co.ke');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('INV-KEEP-00001', $e->errors()['domain'][0]);
            $this->assertStringContainsString('simpleproject.co.ke', $e->errors()['domain'][0]);
        }

        $this->assertSame(1, Domain::count());
    }

    public function test_rejects_active_domain_owned_by_the_customer(): void
    {
        $user = User::factory()->customer()->create();
        Domain::create([
            'user_id' => $user->id,
            'name' => 'simpleproject',
            'extension' => '.co.ke',
            'status' => 'active',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already in your account');

        $this->service()->createOrReuse($user, 'simpleproject', '.co.ke');
    }

    public function test_rejects_domain_owned_by_another_customer(): void
    {
        $owner = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        Domain::create([
            'user_id' => $owner->id,
            'name' => 'simpleproject',
            'extension' => '.co.ke',
            'status' => 'pending',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already registered on this platform');

        $this->service()->createOrReuse($other, 'simpleproject', '.co.ke');
    }

    public function test_reclaims_a_cancelled_domain_for_a_new_registration(): void
    {
        $previous = User::factory()->customer()->create();
        $customer = User::factory()->customer()->create();
        $cancelled = Domain::create([
            'user_id' => $previous->id,
            'name' => 'simpleproject',
            'extension' => '.co.ke',
            'status' => 'cancelled',
            'registrar_external_id' => 'OP-OLD',
            'registrar_handle' => 'simpleproject.co.ke',
        ]);

        $domain = $this->service()->createOrReuse($customer, 'simpleproject', '.co.ke', [
            'status' => 'pending',
        ]);

        $this->assertSame($cancelled->id, $domain->id);
        $this->assertSame($customer->id, $domain->user_id);
        $this->assertSame('pending', $domain->status);
        $this->assertNull($domain->registrar_external_id);
        $this->assertSame(1, Domain::count());
    }

    private function service(): CheckoutDomainRegistrationService
    {
        return app(CheckoutDomainRegistrationService::class);
    }
}
