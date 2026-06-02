<?php

namespace Tests\Feature\Admin;

use App\Models\Node;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsFormSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_settings_update_accepts_ajax_form_payload(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('admin.settings.update'), [
            'settings' => [
                'site_name' => 'Updated Site Name',
                'provisioning_mode' => 'automatic',
                'auto_provision' => '1',
            ],
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('Updated Site Name', Setting::getValue('site_name'));
        $this->assertSame('automatic', Setting::getValue('provisioning_mode'));
    }

    public function test_settings_update_normalizes_duplicate_checkbox_values(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('admin.settings.update'), [
            'settings' => [
                'tax_enabled' => ['0', '1'],
                'tax_inclusive' => ['0'],
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame('1', Setting::getValue('tax_enabled'));
        $this->assertSame('0', Setting::getValue('tax_inclusive'));
    }

    public function test_billing_reseller_auto_pay_setting_persists(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('admin.settings.update'), [
            'settings' => [
                'reseller_auto_pay_subscription_from_wallet' => '0',
                'reseller_package_invoice_advance_days' => '10',
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame('0', Setting::getValue('reseller_auto_pay_subscription_from_wallet'));
        $this->assertSame('10', Setting::getValue('reseller_package_invoice_advance_days'));
    }

    public function test_payment_gateway_save_can_disable_mpesa(): void
    {
        Setting::setValue('mpesa_enabled', '1');

        $response = $this->actingAs($this->admin)->postJson(route('admin.settings.update'), [
            'settings' => [
                'mpesa_enabled' => '0',
                'mpesa_environment' => 'sandbox',
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame('0', Setting::getValue('mpesa_enabled'));
    }

    public function test_mpesa_registration_response_type_setting_persists(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('admin.settings.update'), [
            'settings' => [
                'mpesa_environment' => 'production',
                'mpesa_register_response_type' => 'Cancelled',
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame('production', Setting::getValue('mpesa_environment'));
        $this->assertSame('Cancelled', Setting::getValue('mpesa_register_response_type'));
    }

    public function test_node_nameservers_update_accepts_ajax_payload(): void
    {
        $node = Node::factory()->create([
            'type' => 'directadmin',
            'nameserver_1' => 'ns1.old.test',
            'nameserver_2' => 'ns2.old.test',
        ]);

        $response = $this->actingAs($this->admin)->postJson(route('admin.settings.update-node-nameservers'), [
            'nodes' => [
                $node->id => [
                    'nameserver_1' => 'ns1.new.test',
                    'nameserver_2' => 'ns2.new.test',
                    'nameserver_3' => '',
                    'nameserver_4' => '',
                ],
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $node->refresh();
        $this->assertSame('ns1.new.test', $node->nameserver_1);
        $this->assertSame('ns2.new.test', $node->nameserver_2);
    }

    public function test_node_nameservers_update_validates_required_ns1(): void
    {
        $node = Node::factory()->create(['type' => 'directadmin']);

        $response = $this->actingAs($this->admin)->postJson(route('admin.settings.update-node-nameservers'), [
            'nodes' => [
                $node->id => [
                    'nameserver_1' => '',
                ],
            ],
        ]);

        $response->assertUnprocessable();
    }
}
