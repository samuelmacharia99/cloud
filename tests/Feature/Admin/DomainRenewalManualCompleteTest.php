<?php

namespace Tests\Feature\Admin;

use App\Mail\DomainRenewalCompletedMail;
use App\Models\Domain;
use App\Models\DomainRenewalOrder;
use App\Models\ResellerPackage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DomainRenewalManualCompleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('smtp_host', 'smtp.example.com');
        Setting::setValue('smtp_port', '587');
        Setting::setValue('smtp_from_address', 'noreply@example.com');
        Setting::setValue('notify_domain_renewal_completed', 'true');
    }

    private function createReseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter '.uniqid(),
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    private function createRenewalOrder(User $billingUser, Domain $domain, string $status = 'pushed'): DomainRenewalOrder
    {
        return DomainRenewalOrder::create([
            'domain_id' => $domain->id,
            'user_id' => $billingUser->id,
            'years' => 1,
            'amount' => 1400,
            'status' => $status,
            'pushed_at' => $status === 'pushed' ? now() : null,
            'expires_at' => now()->addDays(10),
        ]);
    }

    public function test_admin_can_manually_complete_renewal_and_update_domain_expiry(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $customer = User::factory()->create([
            'reseller_id' => $reseller->id,
            'email' => 'end-customer@example.com',
        ]);

        $currentExpiry = now()->addMonths(2);
        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'jameskahiga',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'expires_at' => $currentExpiry,
        ]);

        $renewal = $this->createRenewalOrder($reseller, $domain);

        $this->actingAs($admin)
            ->post(route('admin.domain-renewals.complete-manually', $renewal), [
                'years' => 2,
                'admin_notes' => 'Renewed at Openprovider manually',
                'send_notification' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $renewal->refresh();
        $domain->refresh();

        $this->assertSame('completed', $renewal->status);
        $this->assertSame(2, $renewal->years);
        $this->assertTrue($domain->expires_at->equalTo($currentExpiry->copy()->addYears(2)));

        Mail::assertQueued(DomainRenewalCompletedMail::class, function (DomainRenewalCompletedMail $mail) use ($reseller, $customer) {
            return $mail->hasTo($reseller->email)
                && ! $mail->hasTo($customer->email)
                && $mail->years === 2
                && $mail->endCustomerName === $customer->name;
        });
    }

    public function test_manual_complete_rejects_non_actionable_status(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'owned',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'expires_at' => now()->addYear(),
        ]);

        $renewal = $this->createRenewalOrder($reseller, $domain, 'completed');

        $this->actingAs($admin)
            ->post(route('admin.domain-renewals.complete-manually', $renewal), [
                'years' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_expired_domain_renews_from_today_when_manually_completed(): void
    {
        Mail::fake();
        Setting::updateOrCreate(['key' => 'notify_domain_renewal_completed'], ['value' => 'true']);

        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'expired',
            'extension' => '.com',
            'status' => 'expired',
            'type' => 'registration',
            'expires_at' => now()->subMonth(),
        ]);

        $renewal = $this->createRenewalOrder($reseller, $domain);

        $this->actingAs($admin)
            ->post(route('admin.domain-renewals.complete-manually', $renewal), [
                'years' => 1,
                'send_notification' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $domain->refresh();

        $this->assertSame('active', $domain->status);
        $this->assertTrue($domain->expires_at->greaterThan(now()->addMonths(11)));
        $this->assertTrue($domain->expires_at->lessThan(now()->addYears(1)->addDay()));
    }
}
