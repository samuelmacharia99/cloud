<?php

namespace Tests\Feature\Customer;

use App\Enums\RegistrarDriver;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\Registrar;
use App\Models\Setting;
use App\Models\User;
use App\Support\SessionCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerDomainRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_sees_live_epp_and_cannot_see_cosmotown(): void
    {
        $customer = User::factory()->customer()->create();
        $this->attachCosmotown('.com');
        $domain = $this->domain($customer);

        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'shop.com',
                'nameservers' => ['ns1.live.example', 'ns2.live.example'],
                'locked' => true,
                'whois_privacy' => false,
                'auth_code' => 'EPP-CUSTOMER-401',
            ], 200),
        ]);

        $this->actingAs($customer)
            ->get(route('customer.domains.show', $domain))
            ->assertOk()
            ->assertSee('EPP-CUSTOMER-401')
            ->assertSee('Live from registry')
            ->assertDontSee('Cosmotown')
            ->assertDontSee('cosmotown');
    }

    public function test_customer_nameserver_save_pushes_to_registry(): void
    {
        $customer = User::factory()->customer()->create();
        $this->attachCosmotown('.com');
        $domain = $this->domain($customer);

        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'shop.com',
                'nameservers' => ['ns1.old.com', 'ns2.old.com'],
            ], 200),
            'sandbox.cosmotown.com/v1/reseller/savedomainnameservers' => Http::response(['status' => 'processed'], 200),
        ]);

        $this->actingAs($customer)
            ->put(route('customer.domains.nameservers', $domain), [
                'nameserver_1' => 'ns1.talksasa.com',
                'nameserver_2' => 'ns2.talksasa.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'reseller/savedomainnameservers')
            && ($request->data()['nameservers'] ?? []) === ['ns1.talksasa.com', 'ns2.talksasa.com']);
    }

    public function test_customer_nameserver_save_requires_two_unique_nameservers(): void
    {
        $customer = User::factory()->customer()->create();
        $domain = $this->domain($customer);

        $this->actingAs($customer)
            ->from(route('customer.domains.show', $domain))
            ->put(route('customer.domains.nameservers', $domain), [
                'nameserver_1' => 'ns1.same.com',
                'nameserver_2' => 'ns1.same.com',
            ])
            ->assertRedirect(route('customer.domains.show', $domain))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'nameserver_1' => 'ns1.old.com',
        ]);
    }

    public function test_customer_can_push_registrant_and_unlock_with_confirmation(): void
    {
        $customer = User::factory()->customer()->create([
            'address' => '1 Kenyatta Ave',
            'city' => 'Nairobi',
            'country' => 'KE',
            'phone' => '+254700000001',
        ]);
        $this->attachCosmotown('.com');
        $domain = $this->domain($customer);
        $domain->update(['registry_locked' => true]);

        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/contactinfo*' => Http::response(['status' => 'processed'], 200),
            'sandbox.cosmotown.com/v1/reseller/domaininfo' => Http::response(['status' => 'processed'], 200),
        ]);

        $this->actingAs($customer)
            ->put(route('customer.domains.registrant', $domain), [
                'registrant' => [
                    'first_name' => 'Amina',
                    'last_name' => 'Otieno',
                    'email' => 'amina@example.com',
                    'phone' => '+254700000001',
                    'address1' => '1 Kenyatta Ave',
                    'city' => 'Nairobi',
                    'country' => 'KE',
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($customer)
            ->from(route('customer.domains.show', $domain))
            ->put(route('customer.domains.registry-options', $domain), [
                'registry_locked' => '0',
                'whois_privacy' => '1',
            ])
            ->assertRedirect(route('customer.domains.show', $domain))
            ->assertSessionHasErrors('confirm_unlock');

        $this->actingAs($customer)
            ->put(route('customer.domains.registry-options', $domain), [
                'registry_locked' => '0',
                'whois_privacy' => '1',
                'confirm_unlock' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'registry_locked' => false,
            'whois_privacy' => true,
        ]);
        $this->assertSame('Amina', $domain->fresh()->registrant_contact['first_name']);
    }

    public function test_other_customer_cannot_open_domain(): void
    {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $domain = $this->domain($owner);

        $this->actingAs($stranger)
            ->get(route('customer.domains.show', $domain))
            ->assertForbidden();
    }

    public function test_checkout_rejects_domain_without_registrant_and_does_not_create_the_domain(): void
    {
        $customer = User::factory()->customer()->create();
        $this->seedRetailCom();
        Setting::setValue('tax_enabled', 'false');

        $this->actingAs($customer);
        SessionCart::putPortal([
            'domain_whois' => [
                'type' => 'domain',
                'domain' => 'whoismissing',
                'extension' => '.com',
                'years' => 1,
                'nameservers' => [
                    'use_default' => true,
                    'ns1' => 'ns1.talksasa.com',
                    'ns2' => 'ns2.talksasa.com',
                ],
            ],
        ]);

        $this->from(route('customer.checkout.show'))
            ->post(route('customer.checkout.process'), [
                'agree_terms' => '1',
            ])
            ->assertRedirect(route('customer.checkout.show'))
            ->assertSessionHasErrors('registrant.first_name');

        $this->assertDatabaseMissing('domains', ['name' => 'whoismissing']);
    }

    public function test_checkout_stores_submitted_registrant_on_the_domain(): void
    {
        $customer = User::factory()->customer()->create();
        $this->seedRetailCom();
        Setting::setValue('tax_enabled', 'false');

        $this->actingAs($customer);
        SessionCart::putPortal([
            'domain_whois' => [
                'type' => 'domain',
                'domain' => 'whoisok',
                'extension' => '.com',
                'years' => 1,
                'nameservers' => [
                    'use_default' => true,
                    'ns1' => 'ns1.talksasa.com',
                    'ns2' => 'ns2.talksasa.com',
                ],
            ],
        ]);

        $this->post(route('customer.checkout.process'), $this->withRegistrant([
            'agree_terms' => '1',
        ], $customer))->assertRedirect();

        $domain = Domain::query()->where('name', 'whoisok')->first();
        $this->assertNotNull($domain);
        $this->assertSame('Amina', $domain->registrant_contact['first_name']);
        $this->assertSame('Otieno', $domain->registrant_contact['last_name']);
        $this->assertSame('KE', $domain->registrant_contact['country']);
    }

    private function seedRetailCom(): DomainExtension
    {
        $extension = DomainExtension::query()->firstOrCreate(
            ['extension' => '.com'],
            ['description' => 'COM', 'enabled' => true]
        );

        DomainPricing::query()->firstOrCreate(
            [
                'domain_extension_id' => $extension->id,
                'period_years' => 1,
                'tier' => 'wholesale',
            ],
            ['price' => 1000, 'renewal_price' => 900, 'enabled' => true]
        );
        DomainPricing::query()->firstOrCreate(
            [
                'domain_extension_id' => $extension->id,
                'period_years' => 1,
                'tier' => 'retail',
            ],
            ['price' => 1400, 'renewal_price' => 1200, 'enabled' => true]
        );

        return $extension;
    }

    private function domain(User $owner): Domain
    {
        return Domain::create([
            'user_id' => $owner->id,
            'name' => 'shop',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'registrar_handle' => 'shop.com',
            'nameserver_1' => 'ns1.old.com',
            'nameserver_2' => 'ns2.old.com',
        ]);
    }

    private function attachCosmotown(string $extension): Registrar
    {
        $registrar = Registrar::query()->create([
            'name' => 'Cosmotown',
            'slug' => 'cosmotown-customer-'.uniqid(),
            'driver' => RegistrarDriver::Cosmotown,
            'environment' => 'sandbox',
            'is_active' => true,
            'is_default' => true,
            'config' => ['api_token' => 'test-token'],
            'sort_order' => 0,
        ]);

        DomainExtension::query()->firstOrCreate(
            ['extension' => $extension],
            ['description' => 'COM', 'enabled' => true]
        )->update(['registrar_id' => $registrar->id, 'enabled' => true]);

        return $registrar;
    }
}
