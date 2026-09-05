<?php

namespace Tests\Feature\Admin;

use App\Models\Domain;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDomainOwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->admin()->create();
    }

    private function createReseller(string $name = 'Reseller A'): User
    {
        $package = ResellerPackage::create([
            'name' => 'Pkg '.uniqid(),
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'name' => $name,
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    private function createDomain(User $owner, array $overrides = []): Domain
    {
        return Domain::create(array_merge([
            'user_id' => $owner->id,
            'reseller_id' => $owner->reseller_id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'expires_at' => now()->addYear(),
        ], $overrides));
    }

    public function test_admin_can_open_domain_page_with_change_owner_control(): void
    {
        $admin = $this->createAdmin();
        $owner = User::factory()->create();
        $domain = $this->createDomain($owner);

        $this->actingAs($admin)
            ->get(route('admin.domains.show', $domain))
            ->assertOk()
            ->assertSee('Change owner')
            ->assertSee('Change domain owner')
            ->assertDontSee('Also move the hosting service?');
    }

    public function test_admin_can_transfer_domain_to_another_customer(): void
    {
        $admin = $this->createAdmin();
        $from = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $to = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
        $domain = $this->createDomain($from);

        $this->actingAs($admin)
            ->post(route('admin.domains.transfer-ownership', $domain), [
                'target_user_id' => $to->id,
                'reason' => 'Customer asked to move this domain',
                'confirmation_email' => 'bob@example.com',
            ])
            ->assertRedirect(route('admin.domains.show', $domain))
            ->assertSessionHas('success');

        $domain->refresh();
        $this->assertSame($to->id, $domain->user_id);
        $this->assertNull($domain->pending_transfer_to_user_id);

        $notes = $domain->notes;
        $this->assertIsArray($notes);
        $this->assertSame('admin_ownership_transfer', $notes[0]['type'] ?? null);
        $this->assertSame('Customer asked to move this domain', $notes[0]['reason'] ?? null);

        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'domain.transfer_ownership',
            'subject_type' => Domain::class,
            'subject_id' => $domain->id,
        ]);
    }

    public function test_transfer_updates_reseller_id_for_target_customer(): void
    {
        $admin = $this->createAdmin();
        $resellerA = $this->createReseller('Reseller A');
        $resellerB = $this->createReseller('Reseller B');
        $from = User::factory()->create(['reseller_id' => $resellerA->id]);
        $to = User::factory()->create(['reseller_id' => $resellerB->id, 'email' => 'new-owner@example.com']);
        $domain = $this->createDomain($from);

        $this->actingAs($admin)
            ->post(route('admin.domains.transfer-ownership', $domain), [
                'target_user_id' => $to->id,
                'reason' => 'Moving under the new reseller',
                'confirmation_email' => 'new-owner@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $domain->refresh();
        $this->assertSame($to->id, $domain->user_id);
        $this->assertSame($resellerB->id, $domain->reseller_id);
    }

    public function test_wrong_confirmation_email_does_not_change_owner(): void
    {
        $admin = $this->createAdmin();
        $from = User::factory()->create();
        $to = User::factory()->create(['email' => 'real-owner@example.com']);
        $domain = $this->createDomain($from);

        $this->actingAs($admin)
            ->from(route('admin.domains.show', $domain))
            ->post(route('admin.domains.transfer-ownership', $domain), [
                'target_user_id' => $to->id,
                'reason' => 'Customer request',
                'confirmation_email' => 'wrong@example.com',
            ])
            ->assertRedirect(route('admin.domains.show', $domain))
            ->assertSessionHasErrors('confirmation_email');

        $this->assertSame($from->id, $domain->fresh()->user_id);
    }

    public function test_cannot_transfer_without_a_reason(): void
    {
        $admin = $this->createAdmin();
        $from = User::factory()->create();
        $to = User::factory()->create(['email' => 'to@example.com']);
        $domain = $this->createDomain($from);

        $this->actingAs($admin)
            ->from(route('admin.domains.show', $domain))
            ->post(route('admin.domains.transfer-ownership', $domain), [
                'target_user_id' => $to->id,
                'reason' => '',
                'confirmation_email' => 'to@example.com',
            ])
            ->assertRedirect(route('admin.domains.show', $domain))
            ->assertSessionHasErrors('reason');

        $this->assertSame($from->id, $domain->fresh()->user_id);
    }

    public function test_cannot_transfer_domain_to_same_customer(): void
    {
        $admin = $this->createAdmin();
        $owner = User::factory()->create(['email' => 'same@example.com']);
        $domain = $this->createDomain($owner);

        $this->actingAs($admin)
            ->from(route('admin.domains.show', $domain))
            ->post(route('admin.domains.transfer-ownership', $domain), [
                'target_user_id' => $owner->id,
                'reason' => 'No-op attempt',
                'confirmation_email' => 'same@example.com',
            ])
            ->assertRedirect(route('admin.domains.show', $domain))
            ->assertSessionHasErrors('target_user_id');
    }

    public function test_cannot_transfer_domain_to_reseller_account(): void
    {
        $admin = $this->createAdmin();
        $owner = User::factory()->create();
        $reseller = $this->createReseller();
        $domain = $this->createDomain($owner);

        $this->actingAs($admin)
            ->from(route('admin.domains.show', $domain))
            ->post(route('admin.domains.transfer-ownership', $domain), [
                'target_user_id' => $reseller->id,
                'reason' => 'Tried to assign a reseller',
                'confirmation_email' => $reseller->email,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($owner->id, $domain->fresh()->user_id);
    }

    public function test_open_renewal_invoice_moves_with_domain(): void
    {
        $admin = $this->createAdmin();
        $from = User::factory()->create();
        $to = User::factory()->create(['email' => 'invoice-owner@example.com']);
        $domain = $this->createDomain($from);

        $invoice = Invoice::factory()->create([
            'user_id' => $from->id,
            'invoice_number' => 'INV-DOM-001',
            'status' => 'unpaid',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'domain_id' => $domain->id,
            'description' => 'Domain renewal',
            'quantity' => 1,
            'unit_price' => 1500,
            'amount' => 1500,
        ]);

        DomainRenewalOrder::create([
            'domain_id' => $domain->id,
            'user_id' => $from->id,
            'invoice_id' => $invoice->id,
            'years' => 1,
            'amount' => 1500,
            'status' => 'invoiced',
            'expires_at' => now()->addDays(10),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.domains.transfer-ownership', $domain), [
                'target_user_id' => $to->id,
                'reason' => 'Billing should follow the new owner',
                'confirmation_email' => 'invoice-owner@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($to->id, $invoice->fresh()->user_id);
        $this->assertSame($to->id, DomainRenewalOrder::query()->first()->user_id);
    }

    public function test_mixed_service_invoice_does_not_move(): void
    {
        $admin = $this->createAdmin();
        $from = User::factory()->create();
        $to = User::factory()->create(['email' => 'keep-invoice@example.com']);
        $domain = $this->createDomain($from);
        $service = Service::factory()->create(['user_id' => $from->id]);

        $invoice = Invoice::factory()->create([
            'user_id' => $from->id,
            'status' => 'unpaid',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'domain_id' => $domain->id,
            'description' => 'Domain',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'description' => 'Hosting',
            'quantity' => 1,
            'unit_price' => 2000,
            'amount' => 2000,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.domains.transfer-ownership', $domain), [
                'target_user_id' => $to->id,
                'reason' => 'Domain only',
                'confirmation_email' => 'keep-invoice@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($to->id, $domain->fresh()->user_id);
        $this->assertSame($from->id, $invoice->fresh()->user_id);
        $this->assertSame($from->id, $service->fresh()->user_id);
    }

    public function test_domain_page_asks_whether_to_move_a_linked_service(): void
    {
        $admin = $this->createAdmin();
        $from = User::factory()->create();
        User::factory()->create();
        $domain = $this->createDomain($from);
        Service::factory()->create([
            'user_id' => $from->id,
            'name' => 'Shop Hosting',
            'service_meta' => ['domain_id' => $domain->id],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.domains.show', $domain))
            ->assertOk()
            ->assertSee('Also move the hosting service?')
            ->assertSee('Shop Hosting')
            ->assertSee('move the service')
            ->assertSee('leave the service');
    }

    public function test_linked_service_requires_an_explicit_move_choice(): void
    {
        $admin = $this->createAdmin();
        $from = User::factory()->create();
        $to = User::factory()->create(['email' => 'needs-choice@example.com']);
        $domain = $this->createDomain($from);
        $service = Service::factory()->create([
            'user_id' => $from->id,
            'name' => 'Alice Hosting',
            'service_meta' => ['domain_id' => $domain->id, 'domain' => $domain->fqdn()],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.domains.show', $domain))
            ->post(route('admin.domains.transfer-ownership', $domain), [
                'target_user_id' => $to->id,
                'reason' => 'Customer request',
                'confirmation_email' => 'needs-choice@example.com',
            ])
            ->assertRedirect(route('admin.domains.show', $domain))
            ->assertSessionHasErrors('transfer_services');

        $this->assertSame($from->id, $domain->fresh()->user_id);
        $this->assertSame($from->id, $service->fresh()->user_id);
    }

    public function test_admin_can_leave_linked_service_on_the_previous_owner(): void
    {
        $admin = $this->createAdmin();
        $from = User::factory()->create();
        $to = User::factory()->create(['email' => 'domain-only@example.com']);
        $domain = $this->createDomain($from);
        $service = Service::factory()->create([
            'user_id' => $from->id,
            'name' => 'Stay Here',
            'service_meta' => ['domain_id' => $domain->id],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.domains.transfer-ownership', $domain), [
                'target_user_id' => $to->id,
                'reason' => 'Domain billing only',
                'confirmation_email' => 'domain-only@example.com',
                'transfer_services' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($to->id, $domain->fresh()->user_id);
        $this->assertSame($from->id, $service->fresh()->user_id);
    }

    public function test_admin_can_move_linked_service_with_the_domain(): void
    {
        $admin = $this->createAdmin();
        $from = User::factory()->create();
        $to = User::factory()->create(['email' => 'site-owner@example.com']);
        $domain = $this->createDomain($from);
        $service = Service::factory()->create([
            'user_id' => $from->id,
            'name' => 'Move With Domain',
            'service_meta' => ['domain_id' => $domain->id, 'domain' => $domain->fqdn()],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.domains.transfer-ownership', $domain), [
                'target_user_id' => $to->id,
                'reason' => 'Whole site should follow the domain',
                'confirmation_email' => 'site-owner@example.com',
                'transfer_services' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($to->id, $domain->fresh()->user_id);
        $this->assertSame($to->id, $service->fresh()->user_id);
    }

    public function test_moving_service_also_moves_mixed_domain_and_service_invoice(): void
    {
        $admin = $this->createAdmin();
        $from = User::factory()->create();
        $to = User::factory()->create(['email' => 'bundle-owner@example.com']);
        $domain = $this->createDomain($from);
        $service = Service::factory()->create([
            'user_id' => $from->id,
            'service_meta' => ['domain_id' => $domain->id],
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $from->id,
            'invoice_number' => 'INV-BUNDLE-001',
            'status' => 'unpaid',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'domain_id' => $domain->id,
            'description' => 'Domain',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'description' => 'Hosting',
            'quantity' => 1,
            'unit_price' => 2000,
            'amount' => 2000,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.domains.transfer-ownership', $domain), [
                'target_user_id' => $to->id,
                'reason' => 'Move site and its invoice',
                'confirmation_email' => 'bundle-owner@example.com',
                'transfer_services' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($to->id, $domain->fresh()->user_id);
        $this->assertSame($to->id, $service->fresh()->user_id);
        $this->assertSame($to->id, $invoice->fresh()->user_id);
    }

    public function test_transfer_preview_includes_linked_services(): void
    {
        $admin = $this->createAdmin();
        $from = User::factory()->create(['name' => 'Alice']);
        $to = User::factory()->create(['name' => 'Bob']);
        $domain = $this->createDomain($from);
        $service = Service::factory()->create([
            'user_id' => $from->id,
            'name' => 'Alice Hosting',
            'service_meta' => ['domain_id' => $domain->id],
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.domains.transfer-preview', $domain).'?target_user_id='.$to->id)
            ->assertOk()
            ->assertJsonPath('linked_services.0.id', $service->id)
            ->assertJsonPath('linked_services.0.name', 'Alice Hosting');
    }

    public function test_transfer_preview_returns_json_summary(): void
    {
        $admin = $this->createAdmin();
        $from = User::factory()->create(['name' => 'Alice']);
        $to = User::factory()->create(['name' => 'Bob']);
        $domain = $this->createDomain($from);

        $this->actingAs($admin)
            ->getJson(route('admin.domains.transfer-preview', $domain).'?target_user_id='.$to->id)
            ->assertOk()
            ->assertJsonPath('from.name', 'Alice')
            ->assertJsonPath('to.name', 'Bob')
            ->assertJsonPath('domain.id', $domain->id);
    }

    public function test_customer_cannot_change_domain_ownership(): void
    {
        $from = User::factory()->create();
        $to = User::factory()->create(['email' => 'other@example.com']);
        $domain = $this->createDomain($from);

        $this->actingAs($from)
            ->post(route('admin.domains.transfer-ownership', $domain), [
                'target_user_id' => $to->id,
                'reason' => 'Should be blocked',
                'confirmation_email' => 'other@example.com',
            ])
            ->assertForbidden();

        $this->assertSame($from->id, $domain->fresh()->user_id);
    }
}
